<?php
/**
 * Plugin Name: Channel Auto Poster (تلگرام و بله)
 * Description: ارسال خودکار مقالات جدید وردپرس به کانال تلگرام و بله. توکن ربات و شناسه کانال را در تنظیمات وارد کنید.
 * Version: 1.0.0
 * Author: isacofarsaei.ir
 * Text Domain: channel-auto-poster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CAP_Channel_Auto_Poster {

	public static $inst;

	const OPT      = 'cap_settings';
	const META_SENT = '_cap_sent';
	const META_SKIP = '_cap_skip';

	/** آدرس پایه API هر پیام‌رسان (بله با تلگرام سازگار است) */
	const ENDPOINTS = array(
		'telegram' => 'https://api.telegram.org/bot',
		'bale'     => 'https://tapi.bale.ai/bot',
	);

	public function __construct() {
		self::$inst = $this;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta' ) );
		add_action( 'cap_send_post', array( $this, 'send_post' ) );
		add_action( 'admin_post_cap_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_cap_resend', array( $this, 'handle_resend' ) );
		add_action( 'admin_post_cap_manual', array( $this, 'handle_manual' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'bulk_hooks' ) );
		add_action( 'admin_notices', array( $this, 'post_notice' ) );
	}

	/* ---------------------------------------------------------------- تنظیمات */

	public function settings() {
		return wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'tg_enabled' => 0,
				'tg_token'   => '',
				'tg_chat'    => '',
				'bl_enabled' => 0,
				'bl_token'   => '',
				'bl_chat'    => '',
				'post_types' => array( 'post', 'product' ),
				'send_image' => 1,
				'bot_welcome'   => "سلام {name} عزیز 👋✨\nبه ربات <b>راهنمای انتخاب محصول</b> خوش آمدید 🚗🔧\n\nاینجا می‌توانید:\n🛒 قطعهٔ مناسب خودروی خود را پیدا کنید\n🔧 مشکل خودرو را عیب‌یابی کنید\n💬 با کارشناس صحبت کنید\n\n👇 یکی را انتخاب کنید یا نام قطعه را بنویسید 🔎",
				'bot_image'     => '',
				'hours'         => '',
				'channel_link'  => '',
				'shop_lat'      => '',
				'shop_lng'      => '',
				'post_buttons'  => 1,
				'bot_enabled'   => 0,
				'bot_token'     => '',
				'bot_admin'     => '',
				'use_short'     => 1,
				'footer_on'     => 1,
				'support_phone' => '09191242492',
				'shop_phone'    => '02133971622',
				'support_tg'    => '@thegifted',
				'address'       => 'تهران، خیابان امیر کبیر، نبش کوچه سراج الملک، پلاک 425',
				'bot_id'        => 'isacofarsaei_bot',
				'footer'        => "➖➖➖➖➖➖➖➖➖➖\n📞 پشتیبان: {support_phone}\n☎️ فروشگاه: {shop_phone}\n💬 تلگرام پشتیبان: {support_tg}\n📍 آدرس: {address}\n\n🤖✨ <a href=\"{bot_url}\">راهنمای انتخاب محصول</a> 👈 {bot_handle}",
				'template_product' => "🛒🔥 <b>{title}</b>\n\n💰 قیمت: {price}\n\n📝 {excerpt}\n\n🛍 مشاهده و خرید: {link}\n\n{hashtags}",
				'template'   => "📰✨ <b>{title}</b>\n\n📝 {excerpt}\n\n🔗 ادامه مطلب: {link}\n\n{hashtags}",
				'excerpt_len' => 250,
			)
		);
	}

	public function menu() {
		add_menu_page( 'ارسال به کانال', 'ارسال به کانال', 'manage_options', 'cap-settings', array( $this, 'settings_page' ), 'dashicons-megaphone', 58 );
	}

	public function register_settings() {
		register_setting( 'cap_group', self::OPT, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $in ) {
		$out = array();
		foreach ( array( 'tg_enabled', 'bl_enabled', 'send_image' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		foreach ( array( 'tg_token', 'tg_chat', 'bl_token', 'bl_chat' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? trim( sanitize_text_field( $in[ $k ] ) ) : '';
		}
		$out['post_types']  = ! empty( $in['post_types'] ) && is_array( $in['post_types'] ) ? array_map( 'sanitize_key', $in['post_types'] ) : array( 'post' );
		$out['bot_welcome'] = isset( $in['bot_welcome'] ) ? wp_kses( $in['bot_welcome'], array( 'b' => array(), 'i' => array() ) ) : '';
		$out['bot_image']   = isset( $in['bot_image'] ) ? esc_url_raw( trim( $in['bot_image'] ) ) : '';
		foreach ( array( 'hours', 'channel_link', 'shop_lat', 'shop_lng' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? trim( sanitize_text_field( $in[ $k ] ) ) : '';
		}
		$out['post_buttons'] = empty( $in['post_buttons'] ) ? 0 : 1;
		$out['bot_enabled'] = empty( $in['bot_enabled'] ) ? 0 : 1;
		$out['bot_token']   = isset( $in['bot_token'] ) ? trim( sanitize_text_field( $in['bot_token'] ) ) : '';
		$out['bot_admin']   = isset( $in['bot_admin'] ) ? trim( sanitize_text_field( $in['bot_admin'] ) ) : '';
		foreach ( array( 'use_short', 'footer_on' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		foreach ( array( 'support_phone', 'shop_phone', 'support_tg', 'address' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? trim( sanitize_text_field( $in[ $k ] ) ) : '';
		}
		$out['bot_id'] = isset( $in['bot_id'] ) ? ltrim( trim( sanitize_text_field( $in['bot_id'] ) ), '@' ) : '';
		$out['footer'] = isset( $in['footer'] ) ? wp_kses( $in['footer'], array( 'b' => array(), 'i' => array(), 'u' => array(), 'a' => array( 'href' => array() ), 'code' => array() ) ) : '';
		$out['template_product'] = isset( $in['template_product'] ) ? wp_kses( $in['template_product'], array( 'b' => array(), 'i' => array(), 'u' => array(), 'a' => array( 'href' => array() ), 'code' => array() ) ) : '';
		$out['template']    = isset( $in['template'] ) ? wp_kses( $in['template'], array( 'b' => array(), 'i' => array(), 'u' => array(), 'a' => array( 'href' => array() ), 'code' => array() ) ) : '';
		$out['excerpt_len'] = max( 50, min( 800, (int) ( $in['excerpt_len'] ?? 250 ) ) );
		return $out;
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = $this->settings();
		$name = self::OPT;
		?>
		<div class="wrap" dir="rtl" style="text-align:right">
			<h1>ارسال خودکار مقالات به کانال تلگرام و بله</h1>

			<?php if ( isset( $_GET['cap_msg'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-info"><p><?php echo esc_html( wp_unslash( $_GET['cap_msg'] ) ); // phpcs:ignore ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'cap_group' ); ?>

				<h2>تلگرام</h2>
				<table class="form-table">
					<tr><th>فعال</th><td><label><input type="checkbox" name="<?php echo $name; ?>[tg_enabled]" value="1" <?php checked( $s['tg_enabled'] ); ?>> ارسال به تلگرام</label></td></tr>
					<tr><th>توکن ربات (API Token)</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[tg_token]" value="<?php echo esc_attr( $s['tg_token'] ); ?>" placeholder="123456:ABC-DEF..."><p class="description">از @BotFather دریافت کنید. ربات را در کانال ادمین کنید.</p></td></tr>
					<tr><th>شناسه کانال</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[tg_chat]" value="<?php echo esc_attr( $s['tg_chat'] ); ?>" placeholder="@mychannel یا -100123456789"></td></tr>
				</table>

				<h2>بله</h2>
				<table class="form-table">
					<tr><th>فعال</th><td><label><input type="checkbox" name="<?php echo $name; ?>[bl_enabled]" value="1" <?php checked( $s['bl_enabled'] ); ?>> ارسال به بله</label></td></tr>
					<tr><th>توکن ربات (API Token)</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[bl_token]" value="<?php echo esc_attr( $s['bl_token'] ); ?>" placeholder="توکن از @botfather بله"><p class="description">از ربات «بات‌فادر» در بله دریافت کنید و ربات را ادمین کانال کنید.</p></td></tr>
					<tr><th>شناسه کانال</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[bl_chat]" value="<?php echo esc_attr( $s['bl_chat'] ); ?>" placeholder="@mychannel یا شناسه عددی"></td></tr>
				</table>

				<h2>تنظیمات پیام</h2>
				<table class="form-table">
					<tr><th>نوع نوشته‌ها</th><td>
						<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : if ( 'attachment' === $pt->name ) { continue; } ?>
							<label style="margin-left:12px"><input type="checkbox" name="<?php echo $name; ?>[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $pt->labels->name ); ?></label>
						<?php endforeach; ?>
					</td></tr>
					<tr><th>ارسال تصویر شاخص</th><td><label><input type="checkbox" name="<?php echo $name; ?>[send_image]" value="1" <?php checked( $s['send_image'] ); ?>> تصویر شاخص همراه پیام ارسال شود</label></td></tr>
					<tr><th>طول خلاصه</th><td><input type="number" name="<?php echo $name; ?>[excerpt_len]" value="<?php echo esc_attr( $s['excerpt_len'] ); ?>" min="50" max="800"> کاراکتر</td></tr>
					<tr><th>قالب پیام</th><td>
						<textarea name="<?php echo $name; ?>[template]" rows="7" class="large-text"><?php echo esc_textarea( $s['template'] ); ?></textarea>
						<p class="description">متغیرها: <code>{title}</code> <code>{excerpt}</code> <code>{link}</code> <code>{hashtags}</code> <code>{category}</code> <code>{author}</code> — تگ‌های مجاز: &lt;b&gt; &lt;i&gt; &lt;u&gt; &lt;a&gt; &lt;code&gt;</p>
					</td></tr>
					<tr><th>قالب پیام محصولات</th><td>
						<textarea name="<?php echo $name; ?>[template_product]" rows="6" class="large-text"><?php echo esc_textarea( $s['template_product'] ); ?></textarea>
						<p class="description">برای محصولات ووکامرس. متغیر اضافه: <code>{price}</code> (قیمت)</p>
					</td></tr>
				</table>
				<h2>🔗 لینک و اطلاعات تماس (انتهای هر پیام)</h2>
				<table class="form-table">
					<tr><th>لینک کوتاه</th><td><label><input type="checkbox" name="<?php echo $name; ?>[use_short]" value="1" <?php checked( $s['use_short'] ); ?>> به‌جای آدرس بلند مقاله، لینک کوتاه وردپرس (<code>?p=123</code>) فرستاده شود</label></td></tr>
					<tr><th>دکمه‌های شیشه‌ای</th><td><label><input type="checkbox" name="<?php echo $name; ?>[post_buttons]" value="1" <?php checked( $s['post_buttons'] ); ?>> زیر هر پیام دکمه «مشاهده/خرید»، «راهنمای انتخاب محصول» و «پشتیبان» نمایش داده شود</label></td></tr>
					<tr><th>افزودن فوتر</th><td><label><input type="checkbox" name="<?php echo $name; ?>[footer_on]" value="1" <?php checked( $s['footer_on'] ); ?>> انتهای هر پیام، اطلاعات تماس و لینک ربات اضافه شود</label></td></tr>
					<tr><th>📞 شماره پشتیبان</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[support_phone]" value="<?php echo esc_attr( $s['support_phone'] ); ?>"></td></tr>
					<tr><th>☎️ شماره مغازه</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[shop_phone]" value="<?php echo esc_attr( $s['shop_phone'] ); ?>"></td></tr>
					<tr><th>💬 آیدی تلگرام پشتیبان</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[support_tg]" value="<?php echo esc_attr( $s['support_tg'] ); ?>"></td></tr>
					<tr><th>📍 آدرس فروشگاه</th><td><input type="text" class="large-text" name="<?php echo $name; ?>[address]" value="<?php echo esc_attr( $s['address'] ); ?>"></td></tr>
					<tr><th>🤖 آیدی ربات راهنما</th><td><input type="text" class="regular-text" style="direction:ltr" name="<?php echo $name; ?>[bot_id]" value="<?php echo esc_attr( $s['bot_id'] ); ?>" placeholder="isacofarsaei_bot"></td></tr>
					<tr><th>قالب فوتر</th><td>
						<textarea name="<?php echo $name; ?>[footer]" rows="8" class="large-text"><?php echo esc_textarea( $s['footer'] ); ?></textarea>
						<p class="description">متغیرها: <code>{support_phone}</code> <code>{shop_phone}</code> <code>{support_tg}</code> <code>{address}</code> <code>{bot_url}</code> <code>{bot_handle}</code></p>
					</td></tr>
				</table>
				<?php do_action( 'cap_settings_bot_fields', $s, $name ); ?>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<hr>
			<h2>ارسال دستی</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cap_manual">
				<?php wp_nonce_field( 'cap_manual' ); ?>
				<table class="form-table">
					<tr><th>ارسال یک مقاله</th><td>
						<select name="post_id">
							<option value="0">— انتخاب مقاله (اختیاری) —</option>
							<?php foreach ( get_posts( array( 'numberposts' => 40, 'post_type' => $this->all_types(), 'post_status' => 'publish' ) ) as $p ) : ?>
								<option value="<?php echo (int) $p->ID; ?>"><?php echo esc_html( get_the_title( $p ) . ' (' . get_post_type_object( $p->post_type )->labels->singular_name . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
					<tr><th>یا پیام دلخواه</th><td>
						<textarea name="custom_text" rows="5" class="large-text" placeholder="متن پیام (تگ‌های &lt;b&gt; &lt;i&gt; &lt;a&gt; مجاز است). اگر مقاله انتخاب شده باشد، این فیلد نادیده گرفته می‌شود."></textarea>
						<p><input type="url" name="custom_image" class="regular-text" style="direction:ltr" placeholder="آدرس تصویر (اختیاری)"></p>
					</td></tr>
					<tr><th>فوتر</th><td><label><input type="checkbox" name="with_footer" value="1" checked> اطلاعات تماس و لینک ربات به انتهای پیام اضافه شود</label></td></tr>
					<tr><th>ارسال به</th><td>
						<label><input type="checkbox" name="targets[]" value="telegram" checked> تلگرام</label>
						<label style="margin-right:12px"><input type="checkbox" name="targets[]" value="bale" checked> بله</label>
					</td></tr>
				</table>
				<?php submit_button( '📤 ارسال همین الان', 'primary' ); ?>
			</form>

			<hr>
			<h2>تست اتصال</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cap_test">
				<?php wp_nonce_field( 'cap_test' ); ?>
				<?php submit_button( 'ارسال پیام آزمایشی به کانال‌ها', 'secondary' ); ?>
			</form>
			<?php do_action( 'cap_settings_after' ); ?>
		</div>
		<?php
	}

	/** همهٔ نوع‌نوشته‌های عمومی (نوشته، برگه، محصول، ...) */
	private function all_types() {
		$t = get_post_types( array( 'public' => true ) );
		unset( $t['attachment'] );
		return array_values( $t );
	}

	public function bulk_hooks() {
		foreach ( $this->all_types() as $pt ) {
			add_filter( "bulk_actions-edit-{$pt}", array( $this, 'bulk_add' ) );
			add_filter( "handle_bulk_actions-edit-{$pt}", array( $this, 'bulk_handle' ), 10, 3 );
		}
	}

	public function bulk_add( $a ) {
		$a['cap_send'] = '📤 ارسال به کانال';
		return $a;
	}

	public function bulk_handle( $redirect, $action, $ids ) {
		if ( 'cap_send' !== $action || ! current_user_can( 'edit_others_posts' ) ) {
			return $redirect;
		}
		$ok = 0;
		foreach ( $ids as $id ) {
			$r = $this->send_post( (int) $id );
			if ( $r && in_array( true, $r, true ) ) {
				$ok++;
			}
		}
		return add_query_arg( 'cap_msg', rawurlencode( "ارسال دستی: {$ok} از " . count( $ids ) . ' مورد با موفقیت ارسال شد.' ), $redirect );
	}

	/* ---------------------------------------------------------------- متاباکس */

	public function meta_box() {
		foreach ( $this->all_types() as $pt ) {
			add_meta_box( 'cap_box', 'ارسال به کانال', array( $this, 'meta_box_html' ), $pt, 'side' );
		}
	}

	public function meta_box_html( $post ) {
		wp_nonce_field( 'cap_meta', 'cap_meta_nonce' );
		$sent = get_post_meta( $post->ID, self::META_SENT, true );
		echo '<label><input type="checkbox" name="cap_skip" value="1" ' . checked( get_post_meta( $post->ID, self::META_SKIP, true ), '1', false ) . '> برای این نوشته ارسال نشود</label>';
		if ( $sent ) {
			echo '<p>✅ ارسال شده در ' . esc_html( $sent ) . '</p>';
		}
		if ( 'publish' === $post->post_status ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=cap_resend&post=' . $post->ID ), 'cap_resend_' . $post->ID );
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">📤 ' . ( $sent ? 'ارسال مجدد' : 'ارسال دستی به کانال' ) . '</a></p>';
		} else {
			echo '<p class="description">پس از انتشار، دکمه ارسال دستی اینجا نمایش داده می‌شود.</p>';
		}
	}

	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['cap_meta_nonce'] ) || ! wp_verify_nonce( $_POST['cap_meta_nonce'], 'cap_meta' ) ) { // phpcs:ignore
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['cap_skip'] ) ) {
			update_post_meta( $post_id, self::META_SKIP, '1' );
		} else {
			delete_post_meta( $post_id, self::META_SKIP );
		}
	}

	/* ---------------------------------------------------------------- ارسال */

	public function on_transition( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old ) {
			return;
		}
		if ( ! in_array( $post->post_type, (array) $this->settings()['post_types'], true ) ) {
			return;
		}
		if ( get_post_meta( $post->ID, self::META_SENT, true ) || get_post_meta( $post->ID, self::META_SKIP, true ) ) {
			return;
		}
		// اجرا بعد از ذخیرهٔ کامل نوشته (متا و تصویر شاخص) تا ارسال، ویرایش را کند نکند
		wp_schedule_single_event( time() + 5, 'cap_send_post', array( $post->ID ) );
		if ( ! defined( 'DOING_CRON' ) ) {
			spawn_cron();
		}
	}

	public function send_post( $post_id, $only = array() ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return array();
		}
		$s       = $this->settings();
		$full    = $this->build_message( $post, $s, 3900 );
		$caption = $this->build_message( $post, $s, 1000 );
		$btns    = $this->post_buttons( $post, $s );
		$image   = '';
		if ( $s['send_image'] && has_post_thumbnail( $post ) ) {
			$image = get_the_post_thumbnail_url( $post, 'large' );
		}

		$results = array();
		if ( ( $only ? in_array( 'telegram', $only, true ) : $s['tg_enabled'] ) && $s['tg_token'] && $s['tg_chat'] ) {
			$results['telegram'] = $this->send( 'telegram', $s['tg_token'], $s['tg_chat'], $full, $image, $caption, $btns );
		}
		if ( ( $only ? in_array( 'bale', $only, true ) : $s['bl_enabled'] ) && $s['bl_token'] && $s['bl_chat'] ) {
			$results['bale'] = $this->send( 'bale', $s['bl_token'], $s['bl_chat'], $full, $image, $caption, $btns );
		}

		if ( $results && in_array( true, $results, true ) ) {
			update_post_meta( $post_id, self::META_SENT, current_time( 'mysql' ) );
		}
		return $results;
	}

	/** فوتر: اطلاعات تماس + لینک ربات */
	public function footer( $s ) {
		if ( empty( $s['footer_on'] ) || '' === trim( $s['footer'] ) ) {
			return '';
		}
		$bot = $s['bot_id'];
		return trim( strtr( $s['footer'], array(
			'{support_phone}' => esc_html( $s['support_phone'] ),
			'{shop_phone}'    => esc_html( $s['shop_phone'] ),
			'{support_tg}'    => esc_html( $s['support_tg'] ),
			'{address}'       => esc_html( $s['address'] ),
			'{bot_url}'       => $bot ? esc_url( 'https://t.me/' . $bot ) : '',
			'{bot_handle}'    => $bot ? '@' . esc_html( $bot ) : '',
		) ) );
	}

	/** طول قابل‌مشاهده پیام (بدون تگ‌های HTML) */
	private function visible_len( $html ) {
		return mb_strlen( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/** متن پیام + فوتر؛ خلاصه آنقدر کوتاه می‌شود که کل پیام در $limit بگنجد (فوتر هرگز بریده نمی‌شود) */
	private function build_message( $post, $s, $limit = 3900 ) {
		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
		$excerpt = wp_strip_all_tags( strip_shortcodes( $excerpt ) );
		$excerpt = trim( preg_replace( '/\s+/u', ' ', $excerpt ) );

		$tags       = array();
		$is_product = 'product' === $post->post_type;
		$tag_terms  = $is_product ? get_the_terms( $post->ID, 'product_tag' ) : get_the_tags( $post->ID );
		foreach ( (array) $tag_terms as $t ) {
			if ( $t && isset( $t->name ) ) {
				$tags[] = '#' . preg_replace( '/[\s\-]+/u', '_', $t->name );
			}
		}
		$cats = $is_product ? get_the_terms( $post->ID, 'product_cat' ) : get_the_category( $post->ID );
		$cats = is_array( $cats ) ? array_values( $cats ) : array();
		$price = '';
		if ( $is_product && function_exists( 'wc_get_product' ) && wc_get_product( $post->ID ) ) {
			$price = trim( html_entity_decode( wp_strip_all_tags( wc_get_product( $post->ID )->get_price_html() ), ENT_QUOTES, 'UTF-8' ) );
		}

		$link = $s['use_short'] ? wp_get_shortlink( $post->ID ) : '';
		if ( ! $link ) {
			$link = get_permalink( $post );
		}

		$tpl    = $is_product ? $s['template_product'] : $s['template'];
		$footer = $this->footer( $s );
		$len    = (int) $s['excerpt_len'];

		do {
			$ex = mb_strlen( $excerpt ) > $len ? rtrim( mb_substr( $excerpt, 0, $len ) ) . '…' : $excerpt;
			$body = trim( strtr( $tpl, array(
				'{title}'    => esc_html( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ),
				'{excerpt}'  => esc_html( $ex ),
				'{link}'     => esc_url( $link ),
				'{hashtags}' => esc_html( implode( ' ', array_slice( $tags, 0, 6 ) ) ),
				'{category}' => $cats ? esc_html( $cats[0]->name ) : '',
				'{price}'    => esc_html( $price ),
				'{author}'   => esc_html( get_the_author_meta( 'display_name', $post->post_author ) ),
			) ) );
			$msg = $footer ? $body . "\n\n" . $footer : $body;
			$len -= 40;
		} while ( $this->visible_len( $msg ) > $limit && $len > 0 );

		return $msg;
	}

	/** ارسال به API تلگرام/بله. true در صورت موفقیت، در غیر این صورت متن خطا */
	private function send( $platform, $token, $chat, $text, $image = '', $caption = null, $buttons = array() ) {
		$res = $this->send_once( $platform, $token, $chat, $text, $image, $caption, $buttons );
		if ( true !== $res && $buttons ) {
			// اگر پیام‌رسان دکمه‌ها را نپذیرفت، بدون دکمه دوباره امتحان کن
			$res = $this->send_once( $platform, $token, $chat, $text, $image, $caption, array() );
		}
		return $res;
	}

	private function send_once( $platform, $token, $chat, $text, $image, $caption, $buttons ) {
		$base = self::ENDPOINTS[ $platform ] . $token . '/';
		$kb   = $buttons ? array( 'reply_markup' => wp_json_encode( array( 'inline_keyboard' => $buttons ), JSON_UNESCAPED_UNICODE ) ) : array();

		if ( $image && null === $caption && mb_strlen( $text ) > 1000 ) {
			// متن بلند: ابتدا عکس بدون کپشن، سپس متن کامل (تا فوتر بریده نشود)
			$this->call( $base . 'sendPhoto', array( 'chat_id' => $chat, 'photo' => $image ) );
			$image = '';
		}
		if ( $image ) {
			// کپشن تصویر در تلگرام حداکثر ۱۰۲۴ کاراکتر است
			$res = $this->call( $base . 'sendPhoto', array(
				'chat_id'    => $chat,
				'photo'      => $image,
				'caption'    => null === $caption ? mb_substr( $text, 0, 1000 ) : $caption,
				'parse_mode' => 'HTML',
			) + $kb );
			if ( true === $res ) {
				return true;
			}
			// اگر تصویر مشکل داشت، فقط متن را بفرست
		}
		return $this->call( $base . 'sendMessage', array(
			'chat_id'    => $chat,
			'text'       => $text,
			'parse_mode' => 'HTML',
		) + $kb );
	}

	/** دکمه‌های زیر پیام کانال */
	private function post_buttons( $post, $s ) {
		if ( empty( $s['post_buttons'] ) ) {
			return array();
		}
		$link = $s['use_short'] ? wp_get_shortlink( $post->ID ) : '';
		$link = $link ? $link : get_permalink( $post );
		$rows = array( array( array( 'text' => 'product' === $post->post_type ? '🛒 مشاهده و خرید' : '📖 ادامه مطلب', 'url' => $link ) ) );
		$row2 = array();
		if ( $s['bot_id'] ) {
			$row2[] = array( 'text' => '🤖 راهنمای انتخاب محصول', 'url' => 'https://t.me/' . $s['bot_id'] );
		}
		if ( $s['support_tg'] ) {
			$row2[] = array( 'text' => '💬 پشتیبان', 'url' => 'https://t.me/' . ltrim( $s['support_tg'], '@' ) );
		}
		if ( $row2 ) {
			$rows[] = $row2;
		}
		return $rows;
	}

	private function call( $url, $body ) {
		$r = wp_remote_post( $url, array( 'timeout' => 20, 'body' => $body ) );
		if ( is_wp_error( $r ) ) {
			error_log( '[channel-auto-poster] ' . $r->get_error_message() );
			return $r->get_error_message();
		}
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! empty( $data['ok'] ) ) {
			return true;
		}
		$err = $data['description'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $r ) );
		error_log( '[channel-auto-poster] ' . $err );
		return $err;
	}

	/* ---------------------------------------------------------------- اکشن‌های ادمین */

	private function back( $msg ) {
		wp_safe_redirect( add_query_arg( 'cap_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=cap-settings' ) ) );
		exit;
	}

	public function handle_test() {
		check_admin_referer( 'cap_test' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید' );
		}
		$s   = $this->settings();
		$txt = '✅ پیام آزمایشی از ' . esc_html( get_bloginfo( 'name' ) );
		$out = array();
		if ( $s['tg_token'] && $s['tg_chat'] ) {
			$r     = $this->send( 'telegram', $s['tg_token'], $s['tg_chat'], $txt );
			$out[] = 'تلگرام: ' . ( true === $r ? 'موفق' : $r );
		}
		if ( $s['bl_token'] && $s['bl_chat'] ) {
			$r     = $this->send( 'bale', $s['bl_token'], $s['bl_chat'], $txt );
			$out[] = 'بله: ' . ( true === $r ? 'موفق' : $r );
		}
		$this->back( $out ? implode( ' | ', $out ) : 'ابتدا توکن و شناسه کانال را ذخیره کنید.' );
	}

	private function format_results( $results ) {
		if ( ! $results ) {
			return 'هیچ کانالی تنظیم نشده است (توکن و شناسه کانال را ذخیره کنید).';
		}
		$names = array( 'telegram' => 'تلگرام', 'bale' => 'بله' );
		$out   = array();
		foreach ( $results as $k => $r ) {
			$out[] = $names[ $k ] . ': ' . ( true === $r ? 'موفق ✅' : 'خطا — ' . $r );
		}
		return implode( ' | ', $out );
	}

	private function targets() {
		$t = isset( $_POST['targets'] ) ? array_intersect( (array) $_POST['targets'], array( 'telegram', 'bale' ) ) : array(); // phpcs:ignore
		return array_values( $t );
	}

	public function row_action( $actions, $post ) {
		if ( 'publish' === $post->post_status && current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=cap_resend&post=' . $post->ID ), 'cap_resend_' . $post->ID );
			$actions['cap_send'] = '<a href="' . esc_url( $url ) . '">📤 ارسال به کانال</a>';
		}
		return $actions;
	}

	public function post_notice() {
		if ( isset( $_GET['cap_msg'] ) && ! isset( $_GET['page'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( wp_unslash( $_GET['cap_msg'] ) ) . '</p></div>'; // phpcs:ignore
		}
	}

	public function handle_resend() {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		check_admin_referer( 'cap_resend_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'دسترسی ندارید' );
		}
		$res = $this->send_post( $id );
		$to  = wp_get_referer() ? wp_get_referer() : get_edit_post_link( $id, 'raw' );
		wp_safe_redirect( add_query_arg( 'cap_msg', rawurlencode( $this->format_results( $res ) ), $to ) );
		exit;
	}

	public function handle_manual() {
		check_admin_referer( 'cap_manual' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید' );
		}
		$targets = $this->targets();
		if ( ! $targets ) {
			$this->back( 'حداقل یک مقصد (تلگرام یا بله) را انتخاب کنید.' );
		}
		$post_id = (int) ( $_POST['post_id'] ?? 0 ); // phpcs:ignore
		if ( $post_id ) {
			$this->back( $this->format_results( $this->send_post( $post_id, $targets ) ) );
		}
		$text = isset( $_POST['custom_text'] ) ? wp_kses( wp_unslash( $_POST['custom_text'] ), array( 'b' => array(), 'i' => array(), 'u' => array(), 'a' => array( 'href' => array() ), 'code' => array() ) ) : ''; // phpcs:ignore
		$img  = isset( $_POST['custom_image'] ) ? esc_url_raw( wp_unslash( $_POST['custom_image'] ) ) : ''; // phpcs:ignore
		if ( '' === trim( $text ) ) {
			$this->back( 'یک مقاله انتخاب کنید یا متن پیام را بنویسید.' );
		}
		$s       = $this->settings();
		if ( ! empty( $_POST['with_footer'] ) && $this->footer( $s ) ) { // phpcs:ignore
			$text .= "\n\n" . $this->footer( $s );
		}
		$results = array();
		if ( in_array( 'telegram', $targets, true ) && $s['tg_token'] && $s['tg_chat'] ) {
			$results['telegram'] = $this->send( 'telegram', $s['tg_token'], $s['tg_chat'], $text, $img );
		}
		if ( in_array( 'bale', $targets, true ) && $s['bl_token'] && $s['bl_chat'] ) {
			$results['bale'] = $this->send( 'bale', $s['bl_token'], $s['bl_chat'], $text, $img );
		}
		$this->back( $this->format_results( $results ) );
	}
}

require_once __DIR__ . '/includes/bot.php';

new CAP_Channel_Auto_Poster();
new CAP_Bot();
