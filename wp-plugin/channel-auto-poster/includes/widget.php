<?php
/**
 * چت‌بات «راهنمای خرید» روی خود سایت (دکمه شناور سمت چپ)
 * همان اطلاعات ربات تلگرام: دسته‌بندی/محصولات ووکامرس، عیب‌یابی، تماس، ثبت درخواست کارشناس
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CAP_Widget {

	const PER_PAGE = 6;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 50 );
	}

	private function s() {
		return CAP_Channel_Auto_Poster::$inst->settings();
	}

	public function routes() {
		register_rest_route( 'cap/v1', '/w', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api' ),
			'permission_callback' => '__return_true',
		) );
	}

	/* ------------------------------------------------------------ داده */

	private function tax() {
		return taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category';
	}

	private function ptype() {
		return post_type_exists( 'product' ) ? 'product' : 'post';
	}

	private function clip( $t, $n = 60 ) {
		$t = html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES, 'UTF-8' );
		return mb_strlen( $t ) > $n ? mb_substr( $t, 0, $n - 1 ) . '…' : $t;
	}

	private function price( $id ) {
		if ( function_exists( 'wc_get_product' ) && ( $p = wc_get_product( $id ) ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	private function cards( $posts ) {
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'title' => $this->clip( get_the_title( $p ), 80 ),
				'price' => $this->price( $p->ID ),
				'img'   => get_the_post_thumbnail_url( $p, 'thumbnail' ) ?: '',
				'url'   => wp_get_shortlink( $p->ID ) ?: get_permalink( $p ),
			);
		}
		return $out;
	}

	private function chip( $label, $a, $extra = array() ) {
		return array( 't' => $label, 'a' => $a ) + $extra;
	}

	private function url_chip( $label, $url ) {
		return array( 't' => $label, 'u' => $url );
	}

	/* ------------------------------------------------------------ API */

	public function api( $request ) {
		$d = (array) $request->get_json_params();
		$a = isset( $d['a'] ) ? sanitize_key( $d['a'] ) : 'home';
		$id = isset( $d['id'] ) ? (int) $d['id'] : 0;
		$pg = max( 1, isset( $d['p'] ) ? (int) $d['p'] : 1 );
		$s  = $this->s();

		switch ( $a ) {
			case 'cat':
				return $this->cat( $id, $pg );
			case 'list':
				return $this->lst( isset( $d['m'] ) ? sanitize_key( $d['m'] ) : 'new', $pg );
			case 'parts':
				return $this->parts();
			case 'probs':
				return $this->probs( $id );
			case 'prob':
				return $this->prob( $id );
			case 'search':
				return $this->search( isset( $d['q'] ) ? sanitize_text_field( $d['q'] ) : '' );
			case 'contact':
				return $this->contact();
			case 'lead':
				return $this->lead( $d );
			default:
				return $this->home( $s );
		}
	}

	private function home( $s ) {
		$chips = array(
			$this->chip( '🛒 انتخاب محصول', 'cat', array( 'id' => 0 ) ),
			$this->chip( '🔧 عیب‌یابی مشکل خودرو', 'parts' ),
		);
		if ( function_exists( 'wc_get_product_ids_on_sale' ) ) {
			$chips[] = $this->chip( '🔥 پرفروش‌ها', 'list', array( 'm' => 'top' ) );
			$chips[] = $this->chip( '💥 تخفیف‌ها', 'list', array( 'm' => 'sale' ) );
		}
		$chips[] = $this->chip( '🆕 جدیدترین‌ها', 'list', array( 'm' => 'new' ) );
		$chips[] = $this->chip( '📞 تماس و آدرس', 'contact' );
		$chips[] = $this->chip( '👨‍🔧 درخواست کارشناس', 'leadform' );
		if ( $s['bot_id'] ) {
			$chips[] = $this->url_chip( '🤖 ادامه در تلگرام', 'https://t.me/' . $s['bot_id'] );
		}
		return array( 'text' => $s['widget_hello'], 'chips' => $chips, 'home' => true );
	}

	private function cat( $term_id, $page ) {
		$tax   = $this->tax();
		$chips = array();
		$kids  = get_terms( array( 'taxonomy' => $tax, 'parent' => $term_id, 'hide_empty' => true, 'orderby' => 'name', 'number' => 60 ) );
		if ( ! is_wp_error( $kids ) ) {
			foreach ( $kids as $k ) {
				if ( 'uncategorized' === $k->slug ) {
					continue;
				}
				$chips[] = $this->chip( '🚘 ' . $this->clip( $k->name, 30 ), 'cat', array( 'id' => (int) $k->term_id ) );
			}
		}
		$text  = '🛒 برند / مدل / دسته مورد نظر را انتخاب کنید:';
		$cards = array();
		$more  = 0;
		if ( $term_id ) {
			$t = get_term( $term_id, $tax );
			if ( $t && ! is_wp_error( $t ) ) {
				$text = '📂 ' . $t->name;
				$q    = new WP_Query( array(
					'post_type' => $this->ptype(), 'post_status' => 'publish', 'posts_per_page' => self::PER_PAGE, 'paged' => $page,
					'tax_query' => array( array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => $term_id, 'include_children' => false ) ),
				) );
				$cards = $this->cards( $q->posts );
				$more  = $q->max_num_pages > $page ? $page + 1 : 0;
				if ( ! $cards && ! $chips ) {
					$text .= "\nمحصولی در این دسته نیست؛ «درخواست کارشناس» را بزنید.";
				}
			}
		}
		$res = array( 'text' => $text, 'chips' => $chips, 'cards' => $cards );
		if ( $more ) {
			$res['more'] = array( 'a' => 'cat', 'id' => $term_id, 'p' => $more );
		}
		return $res;
	}

	private function lst( $mode, $page ) {
		$args = array( 'post_type' => $this->ptype(), 'post_status' => 'publish', 'posts_per_page' => self::PER_PAGE, 'paged' => $page );
		$map  = array( 'new' => '🆕 جدیدترین محصولات', 'top' => '🔥 پرفروش‌ترین‌ها', 'sale' => '💥 محصولات تخفیف‌دار' );
		if ( 'top' === $mode ) {
			$args['meta_key'] = 'total_sales'; // phpcs:ignore
			$args['orderby']  = 'meta_value_num';
		} elseif ( 'sale' === $mode && function_exists( 'wc_get_product_ids_on_sale' ) ) {
			$args['post__in'] = array_merge( array( 0 ), wc_get_product_ids_on_sale() );
		} else {
			$mode = 'new';
		}
		$q   = new WP_Query( $args );
		$res = array( 'text' => $map[ $mode ], 'cards' => $this->cards( $q->posts ), 'chips' => array() );
		if ( $q->max_num_pages > $page ) {
			$res['more'] = array( 'a' => 'list', 'm' => $mode, 'p' => $page + 1 );
		}
		return $res;
	}

	private function parts() {
		$chips = array();
		$parts = get_terms( array( 'taxonomy' => CAP_Bot::TAX, 'hide_empty' => true ) );
		if ( ! is_wp_error( $parts ) ) {
			foreach ( $parts as $p ) {
				$chips[] = $this->chip( $p->name, 'probs', array( 'id' => (int) $p->term_id ) );
			}
		}
		return array( 'text' => '🔧 مشکل شما مربوط به کدام بخش است؟', 'chips' => $chips );
	}

	private function probs( $term_id ) {
		$q = new WP_Query( array( 'post_type' => CAP_Bot::CPT, 'post_status' => 'publish', 'posts_per_page' => 40, 'orderby' => 'menu_order title', 'order' => 'ASC',
			'tax_query' => array( array( 'taxonomy' => CAP_Bot::TAX, 'field' => 'term_id', 'terms' => $term_id ) ) ) );
		$chips = array();
		foreach ( $q->posts as $p ) {
			$chips[] = $this->chip( $this->clip( $p->post_title ), 'prob', array( 'id' => $p->ID ) );
		}
		return array( 'text' => '❓ کدام مورد را دارید؟', 'chips' => $chips );
	}

	private function prob( $id ) {
		$p = get_post( $id );
		if ( ! $p || CAP_Bot::CPT !== $p->post_type || 'publish' !== $p->post_status ) {
			return $this->home( $this->s() );
		}
		$text  = '❗️ ' . $p->post_title . "\n\n" . trim( wp_strip_all_tags( preg_replace( '/<br\s*\/?>/i', "\n", $p->post_content ) ) ) . "\n\n⚠️ این راهنما اولیه است؛ برای اطمینان خودرو را به تعمیرگاه مجاز ببرید.";
		$chips = array();
		$rel   = (int) get_post_meta( $p->ID, '_cap_rel_term', true );
		if ( $rel ) {
			$chips[] = $this->chip( '🛒 محصولات مرتبط', 'cat', array( 'id' => $rel ) );
		}
		$chips[] = $this->chip( '👨‍🔧 هنوز مشکل دارم', 'leadform', array( 'n' => $p->post_title ) );
		return array( 'text' => $text, 'chips' => $chips );
	}

	private function search( $q ) {
		if ( mb_strlen( $q ) < 2 ) {
			return array( 'text' => 'لطفاً حداقل ۲ حرف بنویسید.', 'chips' => array() );
		}
		$prod = new WP_Query( array( 'post_type' => $this->ptype(), 'post_status' => 'publish', 's' => $q, 'posts_per_page' => self::PER_PAGE ) );
		$prb  = new WP_Query( array( 'post_type' => CAP_Bot::CPT, 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 4 ) );
		$chips = array();
		foreach ( $prb->posts as $p ) {
			$chips[] = $this->chip( '🔧 ' . $this->clip( $p->post_title ), 'prob', array( 'id' => $p->ID ) );
		}
		$chips[] = $this->chip( '👨‍🔧 درخواست کارشناس', 'leadform', array( 'n' => 'جستجو: ' . $q ) );
		$found = $prod->posts || $prb->posts;
		return array( 'text' => $found ? '🔎 نتایج جستجو برای «' . $q . '»:' : '🔎 موردی پیدا نشد. از منو انتخاب کنید یا با کارشناس صحبت کنید:', 'cards' => $this->cards( $prod->posts ), 'chips' => $chips );
	}

	private function contact() {
		$s     = $this->s();
		$lines = array( '📞✨ راه‌های ارتباطی', '' );
		$chips = array();
		if ( $s['support_phone'] ) {
			$lines[] = '📞 پشتیبان: ' . $s['support_phone'];
			$chips[] = $this->url_chip( '📞 تماس با پشتیبان', 'tel:' . preg_replace( '/\D/', '', $s['support_phone'] ) );
		}
		if ( $s['shop_phone'] ) {
			$lines[] = '☎️ فروشگاه: ' . $s['shop_phone'];
			$chips[] = $this->url_chip( '☎️ تماس با فروشگاه', 'tel:' . preg_replace( '/\D/', '', $s['shop_phone'] ) );
		}
		if ( $s['support_tg'] ) {
			$lines[] = '💬 تلگرام پشتیبان: ' . $s['support_tg'];
			$chips[] = $this->url_chip( '💬 پیام در تلگرام', 'https://t.me/' . ltrim( $s['support_tg'], '@' ) );
		}
		if ( $s['address'] ) {
			$lines[] = '📍 آدرس: ' . $s['address'];
		}
		if ( $s['hours'] ) {
			$lines[] = '🕘 ساعت کاری: ' . $s['hours'];
		}
		if ( $s['shop_lat'] && $s['shop_lng'] ) {
			$chips[] = $this->url_chip( '📍 مسیریابی روی نقشه', 'https://www.google.com/maps?q=' . rawurlencode( $s['shop_lat'] . ',' . $s['shop_lng'] ) );
		}
		return array( 'text' => implode( "\n", $lines ), 'chips' => $chips );
	}

	private function lead( $d ) {
		$name  = isset( $d['name'] ) ? sanitize_text_field( $d['name'] ) : '';
		$phone = isset( $d['phone'] ) ? sanitize_text_field( $d['phone'] ) : '';
		$note  = isset( $d['note'] ) ? sanitize_text_field( $d['note'] ) : '';
		$hp    = ! empty( $d['hp'] );
		$digits = preg_replace( '/\D/', '', strtr( $phone, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) ) );
		if ( $hp ) {
			return array( 'text' => '✅ ثبت شد.', 'chips' => array() );
		}
		if ( mb_strlen( $name ) < 2 || strlen( $digits ) < 8 || strlen( $digits ) > 14 ) {
			return array( 'text' => '❌ نام و شماره تماس را درست وارد کنید.', 'chips' => array(), 'err' => true );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$key = 'cap_wl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 5 ) {
			return array( 'text' => '⏳ تعداد درخواست‌ها زیاد بود؛ کمی بعد دوباره تلاش کنید یا تماس بگیرید.', 'chips' => array(), 'err' => true );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		CAP_Bot::$inst->lead_from_site( $name, $digits, $note ?: 'چت‌بات سایت' );
		$sp = $this->s()['support_phone'];
		return array( 'text' => '✅ درخواست شما ثبت شد؛ کارشناس به‌زودی تماس می‌گیرد 🙏' . ( $sp ? "\n📞 " . $sp : '' ), 'chips' => array() );
	}

	/** تیره/روشن کردن رنگ هگز (pct منفی = تیره‌تر) */
	private function shade( $hex, $pct ) {
		$hex = ltrim( $hex, '#' );
		$out = '#';
		foreach ( array( 0, 2, 4 ) as $o ) {
			$v    = hexdec( substr( $hex, $o, 2 ) );
			$v    = max( 0, min( 255, (int) round( $v * ( 100 + $pct ) / 100 ) ) );
			$out .= str_pad( dechex( $v ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/* ------------------------------------------------------------ نمایش در سایت */

	public function render() {
		$s = $this->s();
		if ( empty( $s['widget_on'] ) || is_admin() || is_feed() ) {
			return;
		}
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) {
			return;
		}
		$color = $s['widget_color'];
		$dark  = $this->shade( $color, -28 );
		$cfg   = array(
			'api'   => esc_url_raw( rest_url( 'cap/v1/w' ) ),
			'title' => $s['widget_title'] ?: 'راهنمای خرید',
		);
		$c  = esc_attr( $color );
		$d  = esc_attr( $dark );
		?>
<style>
#capw-root{--c:<?php echo $c; ?>;--d:<?php echo $d; ?>;--f:inherit;font-family:var(--f);direction:rtl}
#capw-root *{box-sizing:border-box;font-family:var(--f)}
#capw-btn{position:fixed;left:20px;bottom:20px;z-index:99998;width:62px;height:62px;border-radius:50%;border:0;padding:0;cursor:pointer;color:#fff;display:grid;place-items:center;background:linear-gradient(135deg,var(--c),var(--d));box-shadow:0 8px 24px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.35);transition:transform .25s cubic-bezier(.34,1.56,.64,1),box-shadow .25s}
#capw-btn::before{content:"";position:absolute;inset:0;border-radius:50%;background:var(--c);opacity:.5;z-index:-1;animation:capw-ring 2.6s ease-out infinite}
#capw-btn:hover{transform:translateY(-4px) scale(1.1);box-shadow:0 14px 32px rgba(0,0,0,.34),inset 0 1px 0 rgba(255,255,255,.4)}
#capw-btn:active{transform:scale(.95)}
#capw-btn svg{width:30px;height:30px;transition:transform .35s}
#capw-btn:hover svg{transform:rotate(-12deg) scale(1.08)}
#capw-btn .lbl{position:absolute;left:76px;top:50%;transform:translate(-8px,-50%);white-space:nowrap;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);color:#1f2937;padding:8px 16px;border-radius:999px;font-size:14px;font-weight:600;box-shadow:0 6px 18px rgba(0,0,0,.18);opacity:0;pointer-events:none;transition:opacity .2s,transform .25s}
#capw-btn:hover .lbl{opacity:1;transform:translate(0,-50%)}
#capw-btn .dot{position:absolute;top:2px;right:2px;width:16px;height:16px;border-radius:50%;background:#ef4444;border:2px solid #fff;font-size:10px;line-height:12px;text-align:center}
#capw-hi{position:fixed;left:94px;bottom:34px;z-index:99997;max-width:230px;background:#fff;color:#1f2937;border-radius:16px 16px 16px 4px;padding:10px 14px;font-size:13.5px;line-height:1.7;box-shadow:0 10px 28px rgba(0,0,0,.2);cursor:pointer;opacity:0;transform:translateY(8px) scale(.95);pointer-events:none;transition:opacity .3s,transform .3s}
#capw-hi.show{opacity:1;transform:none;pointer-events:auto}
#capw-hi b{position:absolute;top:-8px;right:-8px;width:20px;height:20px;border-radius:50%;background:#6b7280;color:#fff;font-size:11px;line-height:20px;text-align:center}
@keyframes capw-ring{0%{transform:scale(1);opacity:.5}80%,100%{transform:scale(1.7);opacity:0}}
#capw{position:fixed;left:20px;bottom:20px;z-index:99999;width:370px;max-width:calc(100vw - 24px);height:580px;max-height:calc(100vh - 40px);display:none;flex-direction:column;border-radius:22px;overflow:hidden;background:rgba(255,255,255,.88);backdrop-filter:saturate(1.5) blur(18px);-webkit-backdrop-filter:saturate(1.5) blur(18px);border:1px solid rgba(255,255,255,.6);box-shadow:0 20px 60px rgba(0,0,0,.35);color:#1f2937;font-size:14px;line-height:1.75;transform-origin:bottom left}
#capw.open{display:flex;animation:capw-in .3s cubic-bezier(.34,1.3,.64,1)}
@keyframes capw-in{from{opacity:0;transform:translateY(20px) scale(.92)}to{opacity:1;transform:none}}
#capw header{background:linear-gradient(135deg,var(--c),var(--d));color:#fff;padding:14px 16px;display:flex;align-items:center;gap:10px}
#capw header .av{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.22);display:grid;place-items:center;font-size:20px;flex:none}
#capw header .tt{flex:1;line-height:1.3}#capw header .tt b{display:block;font-size:15px}#capw header .tt small{opacity:.85;font-size:12px}
#capw header .tt small::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:#4ade80;margin-left:5px}
#capw header button{background:rgba(255,255,255,.18);border:0;color:#fff;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:15px;transition:background .2s,transform .2s}
#capw header button:hover{background:rgba(255,255,255,.34);transform:rotate(8deg)}
#capw-msgs{flex:1;overflow-y:auto;padding:14px;scroll-behavior:smooth}
#capw-msgs::-webkit-scrollbar{width:6px}#capw-msgs::-webkit-scrollbar-thumb{background:rgba(0,0,0,.18);border-radius:3px}
.capw-b{max-width:90%;padding:10px 14px;border-radius:18px;margin:7px 0;white-space:pre-wrap;word-wrap:break-word;animation:capw-pop .25s ease-out}
@keyframes capw-pop{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.capw-bot{background:rgba(255,255,255,.95);border:1px solid rgba(0,0,0,.06);border-top-right-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.capw-me{background:linear-gradient(135deg,var(--c),var(--d));color:#fff;margin-left:auto;border-top-left-radius:6px}
.capw-typing{display:inline-flex;gap:4px;padding:13px 16px}.capw-typing i{width:7px;height:7px;border-radius:50%;background:#9ca3af;animation:capw-dot 1.1s infinite}.capw-typing i:nth-child(2){animation-delay:.18s}.capw-typing i:nth-child(3){animation-delay:.36s}
@keyframes capw-dot{0%,60%,100%{transform:translateY(0);opacity:.5}30%{transform:translateY(-5px);opacity:1}}
.capw-chips{display:flex;flex-wrap:wrap;gap:7px;margin:8px 0}
.capw-chip{background:rgba(255,255,255,.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border:1px solid var(--c);color:var(--d);border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;box-shadow:0 2px 6px rgba(0,0,0,.06);transition:transform .18s,background .18s,color .18s,box-shadow .18s}
.capw-chip:hover{background:var(--c);color:#fff;transform:translateY(-2px);box-shadow:0 8px 16px rgba(0,0,0,.18)}
.capw-card{display:flex;gap:12px;align-items:center;background:rgba(255,255,255,.85);border:1px solid rgba(0,0,0,.06);border-radius:16px;padding:9px;margin:7px 0;text-decoration:none;color:inherit;box-shadow:0 2px 8px rgba(0,0,0,.06);transition:transform .2s,box-shadow .2s,border-color .2s}
.capw-card:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(0,0,0,.14);border-color:var(--c)}
.capw-card img{width:60px;height:60px;object-fit:cover;border-radius:12px;background:#e5e7eb;flex:none}
.capw-card span{display:block;font-size:13px;font-weight:600}.capw-card em{display:block;font-style:normal;color:#059669;font-weight:800;font-size:13px;margin-top:2px}
#capw form.capw-in{display:flex;gap:8px;padding:12px;border-top:1px solid rgba(0,0,0,.07);background:rgba(255,255,255,.7)}
#capw form.capw-in input{flex:1;min-width:0;border:1px solid #d1d5db;border-radius:999px;padding:10px 16px;font:inherit;background:#fff;outline:0;transition:border-color .2s,box-shadow .2s}
#capw form.capw-in input:focus{border-color:var(--c);box-shadow:0 0 0 3px rgba(37,99,235,.15)}
#capw form.capw-in button{width:42px;height:42px;flex:none;border-radius:50%;border:0;background:linear-gradient(135deg,var(--c),var(--d));color:#fff;cursor:pointer;display:grid;place-items:center;transition:transform .2s}
#capw form.capw-in button:hover{transform:scale(1.1) rotate(-8deg)}
#capw form.capw-in button svg{width:18px;height:18px;transform:scaleX(-1)}
.capw-form{background:rgba(255,255,255,.9);border:1px solid rgba(0,0,0,.06);border-radius:16px;padding:12px;margin:8px 0;display:flex;flex-direction:column;gap:8px}
.capw-form input{border:1px solid #d1d5db;border-radius:12px;padding:9px 12px;font:inherit;outline:0}.capw-form input:focus{border-color:var(--c)}
.capw-form button{background:linear-gradient(135deg,var(--c),var(--d));color:#fff;border:0;border-radius:12px;padding:10px;cursor:pointer;font:inherit;font-weight:700;transition:transform .15s}.capw-form button:hover{transform:translateY(-1px)}
@media(max-width:480px){#capw{left:8px;bottom:8px;width:calc(100vw - 16px);height:calc(100vh - 16px);max-height:none;border-radius:18px}#capw-hi{display:none}}
@media(prefers-reduced-motion:reduce){#capw-root *{animation:none!important;transition:none!important}}
</style>
<div id="capw-root">
<button id="capw-btn" type="button" aria-label="<?php echo esc_attr( $cfg['title'] ); ?>">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.6-.8L3 21l1.9-5.1A8.4 8.4 0 0 1 3 11.5 8.5 8.5 0 0 1 12 3a8.5 8.5 0 0 1 9 8.5z"/><circle cx="8.5" cy="11.5" r=".6" fill="currentColor"/><circle cx="12" cy="11.5" r=".6" fill="currentColor"/><circle cx="15.5" cy="11.5" r=".6" fill="currentColor"/></svg>
	<span class="dot" id="capw-dot">1</span>
	<span class="lbl"><?php echo esc_html( $cfg['title'] ); ?></span>
</button>
<div id="capw-hi"><b id="capw-hix">✕</b>سلام 👋 برای انتخاب قطعه مناسب خودرو کمک می‌خواهید؟</div>
<div id="capw" role="dialog" aria-label="<?php echo esc_attr( $cfg['title'] ); ?>">
	<header>
		<div class="av">🤖</div>
		<div class="tt"><b><?php echo esc_html( $cfg['title'] ); ?></b><small>آنلاین — پاسخگوی شما هستیم</small></div>
		<button type="button" id="capw-home" title="منوی اصلی" aria-label="منوی اصلی">🏠</button>
		<button type="button" id="capw-x" title="بستن" aria-label="بستن">✕</button>
	</header>
	<div id="capw-msgs"></div>
	<form class="capw-in" id="capw-form"><input type="text" id="capw-q" placeholder="نام قطعه یا خودرو را بنویسید…" autocomplete="off"><button aria-label="ارسال"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/></svg></button></form>
</div>
</div>
<script>
(function(){
var API=<?php echo wp_json_encode( $cfg['api'] ); ?>,root=document.getElementById('capw-root'),box=document.getElementById('capw'),btn=document.getElementById('capw-btn'),hi=document.getElementById('capw-hi'),msgs=document.getElementById('capw-msgs'),started=false;
try{var ff=getComputedStyle(document.body).fontFamily;if(ff)root.style.setProperty('--f',ff)}catch(e){}
function el(t,c,x){var e=document.createElement(t);if(c)e.className=c;if(x!=null)e.textContent=x;return e}
function scroll(){msgs.scrollTop=msgs.scrollHeight}
function bubble(t,me){var b=el('div','capw-b '+(me?'capw-me':'capw-bot'),t);msgs.appendChild(b);scroll()}
function typing(){var b=el('div','capw-b capw-bot capw-typing');b.appendChild(el('i'));b.appendChild(el('i'));b.appendChild(el('i'));msgs.appendChild(b);scroll();return b}
function call(p,cb){
  var loading=typing();
  fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)})
  .then(function(r){return r.json()}).then(function(d){loading.remove();render(d);if(cb)cb(d)})
  .catch(function(){loading.remove();bubble('⚠️ خطا در ارتباط؛ دوباره تلاش کنید.',false)});
}
function chips(list){
  if(!list||!list.length)return;var w=el('div','capw-chips');
  list.forEach(function(c){
    var x;
    if(c.u){x=el('a','capw-chip',c.t);x.href=c.u;if(c.u.indexOf('tel:')!==0)x.target='_blank';x.rel='noopener'}
    else{x=el('button','capw-chip',c.t);x.type='button';x.onclick=function(){bubble(c.t,true);act(c)}}
    w.appendChild(x)});
  msgs.appendChild(w);scroll()
}
function act(c){
  if(c.a==='leadform'){leadform(c.n||'');return}
  call({a:c.a,id:c.id,m:c.m,p:c.p});
}
function render(d){
  if(d.text)bubble(d.text,false);
  (d.cards||[]).forEach(function(k){
    var a=el('a','capw-card');a.href=k.url;a.target='_blank';a.rel='noopener';
    if(k.img){var i=el('img');i.src=k.img;i.alt='';i.loading='lazy';a.appendChild(i)}
    var s=el('div');s.appendChild(el('span',null,k.title));if(k.price)s.appendChild(el('em',null,k.price));a.appendChild(s);msgs.appendChild(a)});
  var l=(d.chips||[]).slice();
  if(d.more)l.push({t:'▶️ نمایش بیشتر',a:d.more.a,id:d.more.id,m:d.more.m,p:d.more.p});
  if(!d.home)l.push({t:'🏠 منوی اصلی',a:'home'});
  chips(l);scroll();
}
function leadform(note){
  bubble('👨‍🔧 نام و شماره تماس خود را بنویسید تا کارشناس با شما تماس بگیرد:',false);
  var f=el('form','capw-form');
  var n=el('input');n.placeholder='نام و نام خانوادگی';n.maxLength=60;
  var p=el('input');p.placeholder='شماره موبایل';p.type='tel';p.maxLength=15;p.style.direction='ltr';
  var h=el('input');h.style.display='none';h.tabIndex=-1;h.autocomplete='off';
  var b=el('button',null,'📩 ثبت درخواست');
  f.appendChild(n);f.appendChild(p);f.appendChild(h);f.appendChild(b);msgs.appendChild(f);scroll();n.focus();
  f.onsubmit=function(e){e.preventDefault();b.disabled=true;
    call({a:'lead',name:n.value,phone:p.value,note:note,hp:h.value},function(d){if(d.err)b.disabled=false;else f.remove()})}
}
function hideHi(){hi.classList.remove('show')}
function open(){hideHi();document.getElementById('capw-dot').style.display='none';box.classList.add('open');btn.style.display='none';if(!started){started=true;call({a:'home'})}}
function close(){box.classList.remove('open');btn.style.display='grid'}
btn.onclick=open;hi.onclick=open;document.getElementById('capw-x').onclick=close;
document.getElementById('capw-hix').onclick=function(e){e.stopPropagation();hideHi()};
document.getElementById('capw-home').onclick=function(){call({a:'home'})};
document.getElementById('capw-form').onsubmit=function(e){e.preventDefault();var q=document.getElementById('capw-q');var v=q.value.trim();if(!v)return;bubble(v,true);q.value='';call({a:'search',q:v})};
try{if(!sessionStorage.getItem('capw_hi')){setTimeout(function(){if(!box.classList.contains('open')){hi.classList.add('show');sessionStorage.setItem('capw_hi','1');setTimeout(hideHi,9000)}},4500)}}catch(e){}
})();
</script>
		<?php
	}
}
