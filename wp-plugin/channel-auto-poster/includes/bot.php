<?php
/**
 * ربات تلگرام «راهنمای انتخاب محصول» — داخل افزونه
 * - دسته‌بندی/محصولات به‌صورت زنده از ووکامرس (یا دسته‌ها و نوشته‌ها اگر ووکامرس نباشد) خوانده می‌شود
 * - مشکلات و راه‌حل‌ها در منوی «مشکلات ربات» (نوع‌نوشته اختصاصی) قابل ویرایش‌اند
 * - درخواست کارشناس با شماره تماس ذخیره و برای ادمین ارسال می‌شود
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CAP_Bot {

	const CPT   = 'cap_problem';
	const TAX   = 'cap_part';
	const LEADS = 'cap_leads';
	const SECRET = 'cap_bot_secret';
	const PER_PAGE = 8;

	public function __construct() {
		add_action( 'init', array( $this, 'register_types' ) );
		add_action( 'admin_init', array( $this, 'maybe_seed' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save_meta' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_cap_webhook', array( $this, 'handle_webhook' ) );
		add_action( 'admin_post_cap_lead_del', array( $this, 'handle_lead_del' ) );
		add_action( 'cap_settings_bot_fields', array( $this, 'settings_fields' ), 10, 2 );
		add_action( 'cap_settings_after', array( $this, 'settings_webhook_box' ) );
	}

	private function s() {
		return CAP_Channel_Auto_Poster::$inst->settings();
	}

	/* ------------------------------------------------------- نوع‌نوشته «مشکلات ربات» */

	public function register_types() {
		register_post_type( self::CPT, array(
			'labels'       => array(
				'name' => 'مشکلات ربات', 'singular_name' => 'مشکل', 'add_new' => 'افزودن مشکل', 'add_new_item' => 'افزودن مشکل جدید',
				'edit_item' => 'ویرایش مشکل', 'all_items' => 'همه مشکلات', 'menu_name' => 'مشکلات ربات', 'search_items' => 'جستجوی مشکل',
				'not_found' => 'موردی نیست',
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'menu_icon'    => 'dashicons-sos',
			'menu_position' => 59,
			'supports'     => array( 'title', 'editor' ),
			'show_in_rest' => false, // ویرایشگر کلاسیک: متن ساده با خط جدید
			'taxonomies'   => array( self::TAX ),
		) );
		register_taxonomy( self::TAX, self::CPT, array(
			'labels' => array( 'name' => 'بخش‌های خودرو', 'singular_name' => 'بخش', 'menu_name' => 'بخش‌های خودرو', 'add_new_item' => 'افزودن بخش', 'all_items' => 'همه بخش‌ها' ),
			'hierarchical' => true, 'show_ui' => true, 'show_admin_column' => true, 'public' => false, 'show_in_rest' => false,
		) );
	}

	/** یک‌بار: مشکلات پیش‌فرض را بساز */
	public function maybe_seed() {
		if ( get_option( 'cap_seeded' ) ) {
			return;
		}
		update_option( 'cap_seeded', 1 );
		$seed = include __DIR__ . '/seed.php';
		$ids  = array();
		foreach ( $seed['parts'] as $key => $name ) {
			$t = wp_insert_term( $name, self::TAX, array( 'slug' => 'cap-' . $key ) );
			if ( ! is_wp_error( $t ) ) {
				$ids[ $key ] = (int) $t['term_id'];
			}
		}
		foreach ( $seed['problems'] as $p ) {
			$id = wp_insert_post( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $p[1], 'post_content' => $p[2] ) );
			if ( $id && ! is_wp_error( $id ) && isset( $ids[ $p[0] ] ) ) {
				wp_set_object_terms( $id, array( $ids[ $p[0] ] ), self::TAX );
			}
		}
	}

	/** محصولات مرتبط با هر مشکل */
	public function meta_box() {
		add_meta_box( 'cap_rel', '🛒 دسته محصول مرتبط', array( $this, 'meta_box_html' ), self::CPT, 'side' );
	}

	public function meta_box_html( $post ) {
		wp_nonce_field( 'cap_prob', 'cap_prob_nonce' );
		wp_dropdown_categories( array(
			'taxonomy' => $this->tax(), 'name' => 'cap_rel_term', 'selected' => (int) get_post_meta( $post->ID, '_cap_rel_term', true ),
			'show_option_none' => '— بدون دسته مرتبط —', 'option_none_value' => 0, 'hide_empty' => 0, 'hierarchical' => 1,
		) );
		echo '<p class="description">اگر انتخاب شود، زیر راه‌حل دکمه «محصولات مرتبط» نمایش داده می‌شود.</p>';
	}

	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['cap_prob_nonce'] ) || ! wp_verify_nonce( $_POST['cap_prob_nonce'], 'cap_prob' ) || ! current_user_can( 'edit_post', $post_id ) ) { // phpcs:ignore
			return;
		}
		update_post_meta( $post_id, '_cap_rel_term', (int) ( $_POST['cap_rel_term'] ?? 0 ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------- تنظیمات و webhook */

	public function settings_fields( $s, $name ) {
		?>
		<h2>🤖 ربات راهنمای انتخاب محصول</h2>
		<table class="form-table">
			<tr><th>فعال</th><td><label><input type="checkbox" name="<?php echo $name; ?>[bot_enabled]" value="1" <?php checked( $s['bot_enabled'] ); ?>> ربات به پیام‌ها پاسخ بدهد</label></td></tr>
			<tr><th>توکن ربات</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[bot_token]" value="<?php echo esc_attr( $s['bot_token'] ); ?>" placeholder="توکن @<?php echo esc_attr( $s['bot_id'] ); ?> از BotFather"><p class="description">توکن خودِ ربات (نه ربات کانال). پس از ذخیره، «ثبت webhook» را پایین همین صفحه بزنید.</p></td></tr>
			<tr><th>شناسه عددی ادمین</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[bot_admin]" value="<?php echo esc_attr( $s['bot_admin'] ); ?>"><p class="description">درخواست‌های کارشناس به این چت می‌آید. در ربات <code>/myid</code> بزنید تا شناسه را ببینید.</p></td></tr>
		</table>
		<?php
	}

	public function settings_webhook_box() {
		$url = rest_url( 'cap/v1/bot' );
		?>
		<hr>
		<h2>🔌 ثبت webhook ربات</h2>
		<p>آدرس webhook: <code style="direction:ltr;display:inline-block"><?php echo esc_html( $url ); ?></code></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cap_webhook">
			<?php wp_nonce_field( 'cap_webhook' ); ?>
			<?php submit_button( 'ثبت webhook و فعال‌سازی ربات', 'secondary' ); ?>
		</form>
		<?php
	}

	private function secret() {
		$sec = get_option( self::SECRET );
		if ( ! $sec ) {
			$sec = wp_generate_password( 40, false );
			update_option( self::SECRET, $sec );
		}
		return hash( 'sha256', $sec );
	}

	private function back( $msg ) {
		wp_safe_redirect( add_query_arg( 'cap_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=cap-settings' ) ) );
		exit;
	}

	public function handle_webhook() {
		check_admin_referer( 'cap_webhook' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید' );
		}
		$s = $this->s();
		if ( ! $s['bot_token'] ) {
			$this->back( 'ابتدا توکن ربات را ذخیره کنید.' );
		}
		$r = $this->tg( 'setWebhook', array(
			'url' => rest_url( 'cap/v1/bot' ), 'secret_token' => $this->secret(), 'allowed_updates' => array( 'message', 'callback_query' ),
		) );
		$this->tg( 'setMyCommands', array( 'commands' => array(
			array( 'command' => 'start', 'description' => 'شروع / منوی اصلی' ),
			array( 'command' => 'myid', 'description' => 'نمایش شناسه عددی شما' ),
		) ) );
		$this->back( ! empty( $r['ok'] ) ? 'webhook ثبت شد ✅ — حالا در ربات /start بزنید.' : 'خطا: ' . ( $r['description'] ?? 'پاسخی از تلگرام نرسید (دسترسی سرور به api.telegram.org را بررسی کنید)' ) );
	}

	/* ------------------------------------------------------- درخواست‌ها (leads) */

	public function menu() {
		add_submenu_page( 'cap-settings', 'درخواست‌های مشتری', '📩 درخواست‌های مشتری', 'manage_options', 'cap-leads', array( $this, 'leads_page' ) );
	}

	public function leads_page() {
		$leads = array_reverse( (array) get_option( self::LEADS, array() ) );
		echo '<div class="wrap" dir="rtl" style="text-align:right"><h1>📩 درخواست‌های کارشناس (' . count( $leads ) . ')</h1>';
		echo '<table class="widefat striped"><thead><tr><th>زمان</th><th>مشتری</th><th>شماره</th><th>موضوع</th><th></th></tr></thead><tbody>';
		foreach ( $leads as $l ) {
			$del = wp_nonce_url( admin_url( 'admin-post.php?action=cap_lead_del&id=' . rawurlencode( $l['id'] ) ), 'cap_lead_' . $l['id'] );
			echo '<tr><td>' . esc_html( $l['time'] ) . '</td><td>' . esc_html( $l['name'] . ' ' . $l['user'] ) . '</td><td dir="ltr">' . esc_html( $l['phone'] ) . '</td><td>' . esc_html( $l['note'] ) . '</td><td><a href="' . esc_url( $del ) . '" onclick="return confirm(\'حذف شود؟\')">حذف</a></td></tr>';
		}
		if ( ! $leads ) {
			echo '<tr><td colspan="5">هنوز درخواستی ثبت نشده.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public function handle_lead_del() {
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		check_admin_referer( 'cap_lead_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید' );
		}
		update_option( self::LEADS, array_values( array_filter( (array) get_option( self::LEADS, array() ), function ( $l ) use ( $id ) {
			return $l['id'] !== $id;
		} ) ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=cap-leads' ) );
		exit;
	}

	/* ------------------------------------------------------- API تلگرام */

	private function tg( $method, $params = array() ) {
		$s = $this->s();
		$r = wp_remote_post( 'https://api.telegram.org/bot' . $s['bot_token'] . '/' . $method, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
		) );
		if ( is_wp_error( $r ) ) {
			return array( 'ok' => false, 'description' => $r->get_error_message() );
		}
		$j = json_decode( wp_remote_retrieve_body( $r ), true );
		return is_array( $j ) ? $j : array( 'ok' => false );
	}

	public function routes() {
		register_rest_route( 'cap/v1', '/bot', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/* ------------------------------------------------------- ساخت صفحه‌ها */

	private function tax() {
		return taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category';
	}

	private function ptype() {
		return post_type_exists( 'product' ) ? 'product' : 'post';
	}

	private function btn( $t, $d ) {
		return array( 'text' => $t, 'callback_data' => $d );
	}

	private function home_row() {
		return array( $this->btn( '🏠 منوی اصلی', 'home' ) );
	}

	private function clip( $t, $n = 58 ) {
		$t = html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES, 'UTF-8' );
		return mb_strlen( $t ) > $n ? mb_substr( $t, 0, $n - 1 ) . '…' : $t;
	}

	private function price( $id ) {
		if ( function_exists( 'wc_get_product' ) && ( $p = wc_get_product( $id ) ) ) {
			$h = trim( html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ) );
			return $h;
		}
		return '';
	}

	private function contact_text() {
		$s = $this->s();
		$l = array( '📞 <b>راه‌های ارتباطی</b>' );
		if ( $s['support_phone'] ) { $l[] = '📞 پشتیبان: ' . esc_html( $s['support_phone'] ); }
		if ( $s['shop_phone'] )    { $l[] = '☎️ فروشگاه: ' . esc_html( $s['shop_phone'] ); }
		if ( $s['support_tg'] )    { $l[] = '💬 تلگرام پشتیبان: ' . esc_html( $s['support_tg'] ); }
		if ( $s['address'] )       { $l[] = '📍 آدرس: ' . esc_html( $s['address'] ); }
		return implode( "\n", $l );
	}

	/** منوی اصلی */
	private function screen_home() {
		$rows = array(
			array( $this->btn( '🛒 انتخاب محصول', 'c:0:1' ), $this->btn( '🔧 عیب‌یابی مشکل خودرو', 't' ) ),
			array( $this->btn( '📞 تماس و آدرس', 'contact' ), $this->btn( '👨‍🔧 کارشناس', 'agent' ) ),
			array( array( 'text' => '🌐 ورود به سایت', 'url' => home_url( '/' ) ) ),
		);
		return array( "سلام 👋✨\nبه ربات <b>راهنمای انتخاب محصول</b> خوش آمدید.\n\n🚘 از منو انتخاب کنید، یا نام قطعه/خودرو را بنویسید تا برایتان جستجو کنم 🔎", $rows );
	}

	/** دسته‌بندی محصولات: زیردسته‌ها + محصولات */
	private function screen_cat( $term_id, $page ) {
		$tax  = $this->tax();
		$rows = array();
		$kids = get_terms( array( 'taxonomy' => $tax, 'parent' => $term_id, 'hide_empty' => true, 'orderby' => 'name', 'number' => 60 ) );
		$kb   = array();
		if ( ! is_wp_error( $kids ) ) {
			foreach ( $kids as $k ) {
				if ( 'uncategorized' === $k->slug || 'دسته‌بندی-نشده' === $k->name ) {
					continue;
				}
				$kb[] = $this->btn( '🚘 ' . $this->clip( $k->name, 28 ), 'c:' . $k->term_id . ':1' );
			}
		}
		$rows = array_chunk( $kb, 2 );

		$title = "🛒 <b>انتخاب محصول</b>\nبرند / مدل / دسته مورد نظر را انتخاب کنید:";
		$parent = 0;
		if ( $term_id ) {
			$t = get_term( $term_id, $tax );
			if ( $t && ! is_wp_error( $t ) ) {
				$parent = (int) $t->parent;
				$title  = '📂 <b>' . esc_html( $t->name ) . '</b>';
				$q = new WP_Query( array(
					'post_type' => $this->ptype(), 'post_status' => 'publish', 'posts_per_page' => self::PER_PAGE, 'paged' => $page,
					'tax_query' => array( array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => $term_id, 'include_children' => false ) ),
					'no_found_rows' => false,
				) );
				foreach ( $q->posts as $p ) {
					$pr = $this->price( $p->ID );
					$rows[] = array( array( 'text' => '🛍 ' . $this->clip( get_the_title( $p ) . ( $pr ? ' — ' . $pr : '' ) ), 'url' => wp_get_shortlink( $p->ID ) ?: get_permalink( $p ) ) );
				}
				if ( $q->max_num_pages > 1 ) {
					$nav = array();
					if ( $page > 1 ) { $nav[] = $this->btn( '◀️ قبلی', "c:$term_id:" . ( $page - 1 ) ); }
					$nav[] = $this->btn( "📄 $page/{$q->max_num_pages}", 'x' );
					if ( $page < $q->max_num_pages ) { $nav[] = $this->btn( 'بعدی ▶️', "c:$term_id:" . ( $page + 1 ) ); }
					$rows[] = $nav;
				}
				if ( ! $kb && ! $q->posts ) {
					$title .= "\n\nمحصولی در این دسته نیست. «کارشناس» را بزنید یا جستجو کنید.";
				} elseif ( $q->posts ) {
					$title .= "\n\n🛒 محصولات این دسته (روی هر مورد بزنید تا صفحه محصول باز شود):";
				}
			}
			$rows[] = array( $this->btn( '⬅️ برگشت', 'c:' . $parent . ':1' ) );
		}
		$rows[] = $this->home_row();
		return array( $title, $rows );
	}

	/** بخش‌های عیب‌یابی */
	private function screen_parts() {
		$parts = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => true ) );
		$b = array();
		if ( ! is_wp_error( $parts ) ) {
			foreach ( $parts as $p ) {
				$b[] = $this->btn( $this->clip( $p->name, 28 ), 't:' . $p->term_id );
			}
		}
		$rows   = array_chunk( $b, 2 );
		$rows[] = $this->home_row();
		return array( "🔧 <b>عیب‌یابی مشکل خودرو</b>\nمشکل شما مربوط به کدام بخش است؟", $rows );
	}

	private function screen_problems( $term_id ) {
		$t = get_term( $term_id, self::TAX );
		$q = new WP_Query( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 40, 'orderby' => 'menu_order title', 'order' => 'ASC',
			'tax_query' => array( array( 'taxonomy' => self::TAX, 'field' => 'term_id', 'terms' => $term_id ) ) ) );
		$rows = array();
		foreach ( $q->posts as $p ) {
			$rows[] = array( $this->btn( $this->clip( $p->post_title ), 'p:' . $p->ID ) );
		}
		$rows[] = array( $this->btn( '⬅️ برگشت', 't' ) );
		$rows[] = $this->home_row();
		$name = $t && ! is_wp_error( $t ) ? $t->name : '';
		return array( '🔧 <b>' . esc_html( $name ) . "</b>\nکدام مورد را دارید؟", $rows );
	}

	private function screen_problem( $id ) {
		$p = get_post( $id );
		if ( ! $p || self::CPT !== $p->post_type || 'publish' !== $p->post_status ) {
			return $this->screen_home();
		}
		$text = trim( wp_strip_all_tags( preg_replace( '/<br\s*\/?>/i', "\n", $p->post_content ) ) );
		$terms = get_the_terms( $p->ID, self::TAX );
		$back  = is_array( $terms ) && $terms ? 't:' . $terms[0]->term_id : 't';
		$rows  = array();
		$rel   = (int) get_post_meta( $p->ID, '_cap_rel_term', true );
		if ( $rel ) {
			$rows[] = array( $this->btn( '🛒 محصولات مرتبط', 'c:' . $rel . ':1' ) );
		}
		$rows[] = array( $this->btn( '✅ مشکلم حل شد', 'solved' ), $this->btn( '❌ هنوز مشکل دارم', 'agent:' . $p->ID ) );
		$rows[] = array( $this->btn( '⬅️ برگشت', $back ) );
		$rows[] = $this->home_row();
		return array( '❗️ <b>' . esc_html( $p->post_title ) . "</b>\n\n" . esc_html( $text ) . "\n\n⚠️ این راهنما اولیه است؛ برای اطمینان خودرو را به تعمیرگاه مجاز ببرید.", $rows );
	}

	private function dispatch( $data ) {
		$p = explode( ':', $data );
		switch ( $p[0] ) {
			case 'c':
				return $this->screen_cat( (int) ( $p[1] ?? 0 ), max( 1, (int) ( $p[2] ?? 1 ) ) );
			case 't':
				return isset( $p[1] ) ? $this->screen_problems( (int) $p[1] ) : $this->screen_parts();
			case 'p':
				return $this->screen_problem( (int) ( $p[1] ?? 0 ) );
			case 'contact':
				return array( $this->contact_text(), array( $this->home_row() ) );
			default:
				return $this->screen_home();
		}
	}

	private function show( $chat, $mid, $screen ) {
		list( $text, $rows ) = $screen;
		$pl = array( 'chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true, 'reply_markup' => array( 'inline_keyboard' => $rows ) );
		if ( $mid ) {
			$pl['message_id'] = $mid;
			$r = $this->tg( 'editMessageText', $pl );
			if ( empty( $r['ok'] ) && false === strpos( (string) ( $r['description'] ?? '' ), 'not modified' ) ) {
				unset( $pl['message_id'] );
				$this->tg( 'sendMessage', $pl );
			}
		} else {
			$this->tg( 'sendMessage', $pl );
		}
	}

	/* ------------------------------------------------------- جستجو و درخواست کارشناس */

	private function search( $chat, $q ) {
		$rows = array();
		$prod = new WP_Query( array( 'post_type' => $this->ptype(), 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 6 ) );
		foreach ( $prod->posts as $p ) {
			$pr = $this->price( $p->ID );
			$rows[] = array( array( 'text' => '🛍 ' . $this->clip( get_the_title( $p ) . ( $pr ? ' — ' . $pr : '' ) ), 'url' => wp_get_shortlink( $p->ID ) ?: get_permalink( $p ) ) );
		}
		$prb = new WP_Query( array( 'post_type' => self::CPT, 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 4 ) );
		foreach ( $prb->posts as $p ) {
			$rows[] = array( $this->btn( '🔧 ' . $this->clip( $p->post_title ), 'p:' . $p->ID ) );
		}
		$rows[] = array( $this->btn( '👨‍🔧 کارشناس', 'agent' ) );
		$rows[] = $this->home_row();
		$found  = $prod->posts || $prb->posts;
		$this->tg( 'sendMessage', array( 'chat_id' => $chat, 'text' => $found ? '🔎 نتایج نزدیک به جستجوی شما:' : '🔎 موردی پیدا نشد. از منو انتخاب کنید یا با کارشناس صحبت کنید:', 'reply_markup' => array( 'inline_keyboard' => $rows ) ) );
	}

	private function ask_phone( $chat, $note ) {
		set_transient( 'cap_st_' . $chat, $note, 2 * HOUR_IN_SECONDS );
		$this->tg( 'sendMessage', array( 'chat_id' => $chat, 'text' => '👨‍🔧 برای ثبت درخواست، شماره تماس خود را با دکمه زیر بفرستید (یا شماره را تایپ کنید) 📱', 'reply_markup' => array(
			'resize_keyboard' => true, 'one_time_keyboard' => true,
			'keyboard' => array( array( array( 'text' => '📱 ارسال شماره من', 'request_contact' => true ) ), array( array( 'text' => 'انصراف' ) ) ),
		) ) );
	}

	private function save_lead( $from, $chat, $note, $phone ) {
		$s    = $this->s();
		$name = trim( ( $from['first_name'] ?? '' ) . ' ' . ( $from['last_name'] ?? '' ) );
		$user = isset( $from['username'] ) ? '@' . $from['username'] : '';
		$l    = (array) get_option( self::LEADS, array() );
		$l[]  = array( 'id' => wp_generate_password( 8, false ), 'time' => current_time( 'Y-m-d H:i' ), 'chat' => $chat, 'name' => $name, 'user' => $user, 'phone' => $phone, 'note' => $note );
		update_option( self::LEADS, array_slice( $l, -300 ), false );
		if ( $s['bot_admin'] ) {
			$this->tg( 'sendMessage', array( 'chat_id' => $s['bot_admin'], 'parse_mode' => 'HTML', 'text' =>
				"📩 <b>درخواست کارشناس</b>\n👤 " . esc_html( $name . ' ' . $user ) . "\n📞 " . esc_html( $phone ) . "\n📝 " . esc_html( $note ) . "\n<a href=\"tg://user?id=$chat\">پیام به کاربر</a>" ) );
		}
	}

	/* ------------------------------------------------------- webhook */

	public function webhook( $request ) {
		$s = $this->s();
		if ( empty( $s['bot_enabled'] ) || ! $s['bot_token'] ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		if ( ! hash_equals( $this->secret(), (string) $request->get_header( 'x_telegram_bot_api_secret_token' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$u = $request->get_json_params();

		if ( ! empty( $u['callback_query'] ) ) {
			$q    = $u['callback_query'];
			$chat = $q['message']['chat']['id'];
			$mid  = $q['message']['message_id'];
			$data = (string) ( $q['data'] ?? '' );
			$this->tg( 'answerCallbackQuery', array( 'callback_query_id' => $q['id'] ) );

			if ( 'x' === $data ) {
				return new WP_REST_Response( array( 'ok' => true ), 200 );
			}
			if ( 'solved' === $data ) {
				$this->tg( 'editMessageText', array( 'chat_id' => $chat, 'message_id' => $mid, 'text' => '🙏✨ خوشحالیم که مشکل حل شد. سفر امن!', 'reply_markup' => array( 'inline_keyboard' => array( $this->home_row() ) ) ) );
			} elseif ( 0 === strpos( $data, 'agent' ) ) {
				$pid  = (int) substr( $data, 6 );
				$note = $pid ? get_the_title( $pid ) : '—';
				$this->ask_phone( $chat, $note );
			} else {
				$this->show( $chat, $mid, $this->dispatch( $data ) );
			}

		} elseif ( ! empty( $u['message'] ) ) {
			$m    = $u['message'];
			$chat = $m['chat']['id'];
			$text = trim( (string) ( $m['text'] ?? '' ) );
			$st   = get_transient( 'cap_st_' . $chat );

			if ( '/myid' === $text ) {
				$this->tg( 'sendMessage', array( 'chat_id' => $chat, 'text' => "شناسه عددی شما: $chat" ) );
			} elseif ( false !== $st && ( 'انصراف' === $text || '/start' === $text ) ) {
				delete_transient( 'cap_st_' . $chat );
				$this->tg( 'sendMessage', array( 'chat_id' => $chat, 'text' => 'لغو شد.', 'reply_markup' => array( 'remove_keyboard' => true ) ) );
				$this->show( $chat, 0, $this->screen_home() );
			} elseif ( false !== $st ) {
				$phone = isset( $m['contact']['phone_number'] ) ? $m['contact']['phone_number'] : ( preg_match( '/^[\d+\s\-۰-۹]{8,16}$/u', $text ) ? $text : '' );
				if ( '' === $phone ) {
					$this->tg( 'sendMessage', array( 'chat_id' => $chat, 'text' => 'لطفاً شماره را با دکمه ارسال کنید یا به‌صورت عدد بنویسید. برای لغو: «انصراف»' ) );
				} else {
					$this->save_lead( $m['from'] ?? array(), $chat, (string) $st, $phone );
					delete_transient( 'cap_st_' . $chat );
					$sp = $this->s()['support_phone'];
					$this->tg( 'sendMessage', array( 'chat_id' => $chat, 'text' => '✅ درخواست شما ثبت شد؛ کارشناس به‌زودی تماس می‌گیرد 🙏' . ( $sp ? "\n📞 $sp" : '' ), 'reply_markup' => array( 'remove_keyboard' => true ) ) );
					$this->show( $chat, 0, $this->screen_home() );
				}
			} elseif ( '' === $text || '/start' === $text || '/menu' === $text ) {
				$this->show( $chat, 0, $this->screen_home() );
			} else {
				$this->search( $chat, $text );
			}
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
