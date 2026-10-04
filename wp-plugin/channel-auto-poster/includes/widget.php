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
			$off = 0;
			if ( function_exists( 'wc_get_product' ) && ( $wp = wc_get_product( $p->ID ) ) && $wp->is_on_sale() && (float) $wp->get_regular_price() > 0 && (float) $wp->get_price() > 0 ) {
				$off = (int) round( ( 1 - (float) $wp->get_price() / (float) $wp->get_regular_price() ) * 100 );
			}
			$out[] = array(
				'off'   => $off,
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
		$tiles = array(
			array( 'i' => '🛒', 't' => 'انتخاب محصول', 'a' => 'cat', 'id' => 0 ),
			array( 'i' => '🔧', 't' => 'عیب‌یابی خودرو', 'a' => 'parts' ),
		);
		if ( function_exists( 'wc_get_product_ids_on_sale' ) ) {
			$tiles[] = array( 'i' => '🔥', 't' => 'پرفروش‌ها', 'a' => 'list', 'm' => 'top' );
			$tiles[] = array( 'i' => '💥', 't' => 'تخفیف‌ها', 'a' => 'list', 'm' => 'sale' );
		}
		$tiles[] = array( 'i' => '🆕', 't' => 'جدیدترین‌ها', 'a' => 'list', 'm' => 'new' );
		$tiles[] = array( 'i' => '📞', 't' => 'تماس و آدرس', 'a' => 'contact' );
		$chips = array( $this->chip( '👨‍🔧 درخواست کارشناس', 'leadform' ) );
		if ( $s['bot_id'] ) {
			$chips[] = $this->url_chip( '🤖 ادامه در تلگرام', 'https://t.me/' . $s['bot_id'] );
		}
		$suggest = array();
		$top     = get_terms( array( 'taxonomy' => $this->tax(), 'parent' => 0, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 4 ) );
		if ( ! is_wp_error( $top ) ) {
			foreach ( $top as $t ) {
				if ( 'uncategorized' !== $t->slug ) {
					$suggest[] = array( 't' => $this->clip( $t->name, 22 ), 'id' => (int) $t->term_id );
				}
			}
		}
		return array( 'text' => $s['widget_hello'], 'tiles' => $tiles, 'chips' => $chips, 'suggest' => $suggest, 'home' => true );
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
		$dark  = $this->shade( $color, -30 );
		$light = $this->shade( $color, 35 );
		$cfg   = array(
			'api'   => esc_url_raw( rest_url( 'cap/v1/w' ) ),
			'title' => $s['widget_title'] ?: 'راهنمای خرید',
			'tel'   => $s['support_phone'] ? 'tel:' . preg_replace( '/\D/', '', $s['support_phone'] ) : '',
			'tg'    => $s['support_tg'] ? 'https://t.me/' . ltrim( $s['support_tg'], '@' ) : '',
			'map'   => ( $s['shop_lat'] && $s['shop_lng'] ) ? 'https://www.google.com/maps?q=' . rawurlencode( $s['shop_lat'] . ',' . $s['shop_lng'] ) : '',
		);
		$c = esc_attr( $color );
		$d = esc_attr( $dark );
		$l = esc_attr( $light );
		?>
<style>
#capw-root{--c:<?php echo $c; ?>;--d:<?php echo $d; ?>;--l:<?php echo $l; ?>;--f:inherit;--bg:rgba(255,255,255,.86);--tx:#1f2937;--bub:rgba(255,255,255,.96);--bd:rgba(0,0,0,.07);--chip:rgba(255,255,255,.62);--card:rgba(255,255,255,.88);--inp:#fff;font-family:var(--f);direction:rtl}
#capw-root *{box-sizing:border-box;font-family:var(--f)}
@media(prefers-color-scheme:dark){#capw-root{--bg:rgba(24,24,30,.88);--tx:#e5e7eb;--bub:rgba(45,45,55,.95);--bd:rgba(255,255,255,.09);--chip:rgba(255,255,255,.07);--card:rgba(45,45,55,.9);--inp:#2b2b34}}
/* ---------- دکمه شناور دایره‌ای با ربات متحرک ---------- */
#capw-btn{position:fixed;left:22px;bottom:22px;z-index:99998;width:66px;height:66px;border-radius:50%;border:0;padding:0;cursor:pointer;color:#fff;display:grid;place-items:center;background:radial-gradient(circle at 30% 25%,var(--l),var(--c) 45%,var(--d));box-shadow:0 10px 28px rgba(0,0,0,.32),inset 0 2px 0 rgba(255,255,255,.4),inset 0 -6px 12px rgba(0,0,0,.15);transition:transform .3s cubic-bezier(.34,1.56,.64,1),box-shadow .3s;animation:capw-float 3.2s ease-in-out infinite}
#capw-btn::before,#capw-btn::after{content:"";position:absolute;inset:0;border-radius:50%;background:var(--c);z-index:-1;opacity:.45;animation:capw-ring 2.8s ease-out infinite}
#capw-btn::after{animation-delay:1.4s}
#capw-btn:hover{transform:translateY(-6px) scale(1.12);box-shadow:0 18px 38px rgba(0,0,0,.4),inset 0 2px 0 rgba(255,255,255,.5);animation-play-state:paused}
#capw-btn:active{transform:scale(.94)}
#capw-btn .bot{width:42px;height:42px;transition:transform .4s cubic-bezier(.34,1.56,.64,1)}
#capw-btn:hover .bot{transform:rotate(-10deg) scale(1.08)}
#capw-btn .lbl{position:absolute;left:80px;top:50%;transform:translate(-10px,-50%) scale(.9);transform-origin:left center;white-space:nowrap;background:linear-gradient(135deg,var(--c),var(--d));color:#fff;padding:9px 18px;border-radius:999px;font-size:14px;font-weight:700;box-shadow:0 8px 20px rgba(0,0,0,.25);opacity:0;pointer-events:none;transition:opacity .22s,transform .3s cubic-bezier(.34,1.56,.64,1)}
#capw-btn:hover .lbl{opacity:1;transform:translate(0,-50%) scale(1)}
#capw-btn .dot{position:absolute;top:0;right:0;min-width:20px;height:20px;border-radius:50%;background:#ef4444;border:2px solid #fff;font-size:11px;font-weight:700;line-height:16px;text-align:center;animation:capw-bounce 1.6s infinite}
@keyframes capw-float{0%,100%{translate:0 0}50%{translate:0 -5px}}
@keyframes capw-ring{0%{transform:scale(1);opacity:.45}80%,100%{transform:scale(1.85);opacity:0}}
@keyframes capw-bounce{0%,100%{transform:scale(1)}50%{transform:scale(1.2)}}
.bot .eye{transform-box:fill-box;transform-origin:center;animation:capw-blink 4.2s infinite}
.bot .ant{animation:capw-glow 1.6s ease-in-out infinite}
.bot .arm{transform-box:fill-box;transform-origin:50% 100%;animation:capw-wave 2.8s ease-in-out infinite}
@keyframes capw-blink{0%,92%,100%{transform:scaleY(1)}96%{transform:scaleY(.1)}}
@keyframes capw-glow{0%,100%{opacity:.5}50%{opacity:1}}
@keyframes capw-wave{0%,60%,100%{transform:rotate(0)}70%{transform:rotate(-18deg)}80%{transform:rotate(10deg)}90%{transform:rotate(-12deg)}}
/* ---------- حباب سلام ---------- */
#capw-hi{position:fixed;left:100px;bottom:38px;z-index:99997;max-width:240px;background:var(--bub);color:var(--tx);backdrop-filter:blur(12px);border:1px solid var(--bd);border-radius:18px 18px 18px 4px;padding:11px 16px;font-size:13.5px;line-height:1.75;box-shadow:0 12px 30px rgba(0,0,0,.22);cursor:pointer;opacity:0;transform:translateY(10px) scale(.92);pointer-events:none;transition:opacity .35s,transform .45s cubic-bezier(.34,1.56,.64,1)}
#capw-hi.show{opacity:1;transform:none;pointer-events:auto}
#capw-hi b{position:absolute;top:-9px;right:-9px;width:22px;height:22px;border-radius:50%;background:#6b7280;color:#fff;font-size:11px;line-height:22px;text-align:center}
#capw-hi .wv{display:inline-block;animation:capw-hand 1.4s infinite;transform-origin:70% 70%}
@keyframes capw-hand{0%,60%,100%{transform:rotate(0)}70%{transform:rotate(18deg)}80%{transform:rotate(-8deg)}90%{transform:rotate(14deg)}}
/* ---------- پنجره ---------- */
#capw{position:fixed;left:22px;bottom:22px;z-index:99999;width:390px;max-width:calc(100vw - 24px);height:620px;max-height:calc(100vh - 44px);display:none;flex-direction:column;border-radius:26px;overflow:hidden;background:var(--bg);backdrop-filter:saturate(1.6) blur(22px);-webkit-backdrop-filter:saturate(1.6) blur(22px);border:1px solid rgba(255,255,255,.35);box-shadow:0 24px 70px rgba(0,0,0,.4);color:var(--tx);font-size:14px;line-height:1.75;transform-origin:bottom left}
#capw.open{display:flex;animation:capw-in .45s cubic-bezier(.34,1.35,.64,1)}
@keyframes capw-in{from{opacity:0;transform:translateY(30px) scale(.85)}to{opacity:1;transform:none}}
#capw header{position:relative;overflow:hidden;background:linear-gradient(120deg,var(--d),var(--c),var(--l),var(--c));background-size:300% 300%;animation:capw-aurora 9s ease infinite;color:#fff;padding:16px 16px 18px;display:flex;align-items:center;gap:12px}
#capw header::after{content:"";position:absolute;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.12);right:-40px;top:-70px}
@keyframes capw-aurora{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
#capw header .av{position:relative;width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.22);display:grid;place-items:center;flex:none;box-shadow:inset 0 0 0 2px rgba(255,255,255,.4)}
#capw header .av .bot{width:36px;height:36px}
#capw header .av::after{content:"";position:absolute;bottom:1px;left:1px;width:12px;height:12px;border-radius:50%;background:#4ade80;border:2px solid #fff}
#capw header .tt{flex:1;line-height:1.35;position:relative;z-index:1}#capw header .tt b{display:block;font-size:16px}#capw header .tt small{opacity:.92;font-size:12px}
#capw header button{position:relative;z-index:1;background:rgba(255,255,255,.2);border:0;color:#fff;border-radius:50%;width:34px;height:34px;cursor:pointer;font-size:15px;transition:background .2s,transform .25s}
#capw header button:hover{background:rgba(255,255,255,.38);transform:rotate(10deg) scale(1.08)}
#capw-msgs{flex:1;overflow-y:auto;padding:14px;scroll-behavior:smooth}
#capw-msgs::-webkit-scrollbar{width:6px}#capw-msgs::-webkit-scrollbar-thumb{background:rgba(128,128,128,.35);border-radius:3px}
.capw-b{position:relative;max-width:88%;width:fit-content;padding:10px 14px;border-radius:20px;margin:7px 0;white-space:pre-wrap;word-wrap:break-word;animation:capw-pop .35s cubic-bezier(.34,1.4,.64,1)}
@keyframes capw-pop{from{opacity:0;transform:translateY(12px) scale(.94)}to{opacity:1;transform:none}}
.capw-bot{background:var(--bub);border:1px solid var(--bd);border-top-right-radius:6px;box-shadow:0 3px 10px rgba(0,0,0,.06)}
.capw-me{background:linear-gradient(135deg,var(--c),var(--d));color:#fff;margin-left:auto;margin-right:0;border-top-left-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.18)}
.capw-t{display:block;font-size:10px;opacity:.55;margin-top:2px;text-align:left}
.capw-typing{display:inline-flex;gap:5px;padding:14px 18px}.capw-typing i{width:8px;height:8px;border-radius:50%;background:var(--c);opacity:.55;animation:capw-dot 1.1s infinite}.capw-typing i:nth-child(2){animation-delay:.18s}.capw-typing i:nth-child(3){animation-delay:.36s}
@keyframes capw-dot{0%,60%,100%{transform:translateY(0);opacity:.4}30%{transform:translateY(-6px);opacity:1}}
/* ---------- کاشی‌های منوی اصلی ---------- */
.capw-tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:10px 0}
.capw-tile{position:relative;overflow:hidden;display:flex;flex-direction:column;align-items:center;gap:4px;padding:16px 8px 13px;border-radius:20px;background:var(--chip);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid var(--bd);color:var(--tx);font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.07);transition:transform .25s cubic-bezier(.34,1.56,.64,1),box-shadow .25s,background .25s,color .25s;animation:capw-pop .5s both}
.capw-tile:nth-child(2){animation-delay:.06s}.capw-tile:nth-child(3){animation-delay:.12s}.capw-tile:nth-child(4){animation-delay:.18s}.capw-tile:nth-child(5){animation-delay:.24s}.capw-tile:nth-child(6){animation-delay:.3s}
.capw-tile i{font-style:normal;font-size:30px;line-height:1.1;transition:transform .35s cubic-bezier(.34,1.56,.64,1)}
.capw-tile::before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,var(--c),var(--d));opacity:0;transition:opacity .25s;z-index:0}
.capw-tile>*{position:relative;z-index:1}
.capw-tile:hover{transform:translateY(-4px) scale(1.03);box-shadow:0 14px 26px rgba(0,0,0,.22);color:#fff}
.capw-tile:hover::before{opacity:1}.capw-tile:hover i{transform:scale(1.25) rotate(-8deg)}
/* ---------- چیپ‌ها ---------- */
.capw-chips{display:flex;flex-wrap:wrap;gap:7px;margin:8px 0}
.capw-chip{background:var(--chip);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border:1px solid var(--c);color:var(--c);border-radius:999px;padding:7px 15px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;box-shadow:0 2px 6px rgba(0,0,0,.06);transition:transform .2s cubic-bezier(.34,1.56,.64,1),background .2s,color .2s,box-shadow .2s;animation:capw-pop .4s both}
.capw-chip:hover{background:var(--c);color:#fff;transform:translateY(-3px) scale(1.04);box-shadow:0 10px 18px rgba(0,0,0,.2)}
.capw-sug{font-size:12px;opacity:.7;margin:10px 4px 2px}
/* ---------- کارت محصول ---------- */
.capw-card{position:relative;display:flex;gap:12px;align-items:center;background:var(--card);border:1px solid var(--bd);border-radius:18px;padding:10px;margin:8px 0;text-decoration:none;color:inherit;box-shadow:0 3px 10px rgba(0,0,0,.07);transition:transform .25s cubic-bezier(.34,1.56,.64,1),box-shadow .25s,border-color .25s;animation:capw-pop .45s both}
.capw-card:hover{transform:translateY(-3px) scale(1.015);box-shadow:0 14px 28px rgba(0,0,0,.2);border-color:var(--c)}
.capw-card img{width:66px;height:66px;object-fit:cover;border-radius:14px;background:rgba(128,128,128,.2);flex:none;transition:transform .3s}
.capw-card:hover img{transform:scale(1.08) rotate(-2deg)}
.capw-card div{flex:1;min-width:0}
.capw-card span{display:block;font-size:13px;font-weight:700;line-height:1.6}
.capw-card em{display:inline-block;font-style:normal;color:#fff;background:linear-gradient(135deg,#10b981,#059669);font-weight:800;font-size:12px;margin-top:4px;padding:2px 10px;border-radius:999px}
.capw-card u{position:absolute;top:-6px;left:-6px;text-decoration:none;background:linear-gradient(135deg,#f43f5e,#e11d48);color:#fff;font-size:11px;font-weight:800;padding:3px 9px;border-radius:999px;box-shadow:0 4px 10px rgba(225,29,72,.4)}
.capw-card::after{content:"‹";font-size:26px;opacity:.35;transition:transform .25s,opacity .25s}
.capw-card:hover::after{opacity:.9;transform:translateX(-4px)}
/* ---------- نوار پایین و ورودی ---------- */
.capw-bar{display:flex;gap:8px;padding:8px 12px 0;background:var(--bg)}
.capw-bar a,.capw-bar button{flex:1;text-align:center;text-decoration:none;color:var(--tx);background:var(--chip);border:1px solid var(--bd);border-radius:12px;padding:6px 4px;font-size:12px;font-weight:600;cursor:pointer;transition:transform .2s,background .2s,color .2s}
.capw-bar a:hover,.capw-bar button:hover{background:var(--c);color:#fff;transform:translateY(-2px)}
#capw form.capw-in{display:flex;gap:8px;padding:10px 12px 12px;background:var(--bg)}
#capw form.capw-in input{flex:1;min-width:0;border:1.5px solid var(--bd);color:var(--tx);border-radius:999px;padding:11px 18px;font:inherit;background:var(--inp);outline:0;transition:border-color .2s,box-shadow .2s}
#capw form.capw-in input:focus{border-color:var(--c);box-shadow:0 0 0 4px rgba(37,99,235,.15)}
#capw form.capw-in button{width:44px;height:44px;flex:none;border-radius:50%;border:0;background:linear-gradient(135deg,var(--c),var(--d));color:#fff;cursor:pointer;display:grid;place-items:center;box-shadow:0 6px 14px rgba(0,0,0,.25);transition:transform .25s cubic-bezier(.34,1.56,.64,1)}
#capw form.capw-in button:hover{transform:scale(1.14) rotate(-10deg)}
#capw form.capw-in button svg{width:19px;height:19px;transform:scaleX(-1)}
.capw-form{background:var(--card);border:1px solid var(--bd);border-radius:18px;padding:14px;margin:8px 0;display:flex;flex-direction:column;gap:9px;animation:capw-pop .4s both}
.capw-form input{border:1.5px solid var(--bd);background:var(--inp);color:var(--tx);border-radius:12px;padding:10px 14px;font:inherit;outline:0}.capw-form input:focus{border-color:var(--c)}
.capw-form button{background:linear-gradient(135deg,var(--c),var(--d));color:#fff;border:0;border-radius:12px;padding:11px;cursor:pointer;font:inherit;font-weight:700;box-shadow:0 6px 14px rgba(0,0,0,.2);transition:transform .2s}.capw-form button:hover{transform:translateY(-2px)}
@media(max-width:480px){#capw{left:8px;bottom:8px;width:calc(100vw - 16px);height:calc(100vh - 16px);max-height:none;border-radius:20px}#capw-hi{display:none}#capw-btn{left:14px;bottom:14px}}
@media(prefers-reduced-motion:reduce){#capw-root *,#capw-root *::before,#capw-root *::after{animation:none!important;transition:none!important}}
</style>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
<symbol id="capw-bot" viewBox="0 0 48 48">
	<line x1="24" y1="4" x2="24" y2="10" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
	<circle class="ant" cx="24" cy="4" r="3" fill="#fde047"/>
	<rect x="7" y="10" width="34" height="27" rx="12" fill="#fff"/>
	<rect x="11" y="15" width="26" height="17" rx="8" fill="#1e293b"/>
	<ellipse class="eye" cx="18.5" cy="23" rx="3" ry="3.6" fill="#67e8f9"/>
	<ellipse class="eye" cx="29.5" cy="23" rx="3" ry="3.6" fill="#67e8f9"/>
	<path d="M20 29q4 3 8 0" stroke="#67e8f9" stroke-width="1.8" fill="none" stroke-linecap="round"/>
	<circle cx="10" cy="26" r="2" fill="#fda4af" opacity=".9"/><circle cx="38" cy="26" r="2" fill="#fda4af" opacity=".9"/>
	<path d="M14 38q10 8 20 0" fill="#fff"/>
</symbol></defs></svg>
<div id="capw-root">
<button id="capw-btn" type="button" aria-label="<?php echo esc_attr( $cfg['title'] ); ?>">
	<svg class="bot" aria-hidden="true"><use href="#capw-bot"/></svg>
	<span class="dot" id="capw-dot">1</span>
	<span class="lbl"><?php echo esc_html( $cfg['title'] ); ?> ✨</span>
</button>
<div id="capw-hi"><b id="capw-hix">✕</b><span class="wv">👋</span> سلام! برای انتخاب قطعه مناسب خودرو کمک می‌خواهید؟</div>
<div id="capw" role="dialog" aria-label="<?php echo esc_attr( $cfg['title'] ); ?>">
	<header>
		<div class="av"><svg class="bot" aria-hidden="true"><use href="#capw-bot"/></svg></div>
		<div class="tt"><b><?php echo esc_html( $cfg['title'] ); ?></b><small>🟢 آنلاین — پاسخگوی شما هستیم</small></div>
		<button type="button" id="capw-home" title="منوی اصلی" aria-label="منوی اصلی">🏠</button>
		<button type="button" id="capw-x" title="بستن" aria-label="بستن">✕</button>
	</header>
	<div id="capw-msgs"></div>
	<div class="capw-bar">
		<?php if ( $cfg['tel'] ) : ?><a href="<?php echo esc_attr( $cfg['tel'] ); ?>">📞 تماس</a><?php endif; ?>
		<?php if ( $cfg['tg'] ) : ?><a href="<?php echo esc_url( $cfg['tg'] ); ?>" target="_blank" rel="noopener">💬 پشتیبان</a><?php endif; ?>
		<?php if ( $cfg['map'] ) : ?><a href="<?php echo esc_url( $cfg['map'] ); ?>" target="_blank" rel="noopener">📍 نقشه</a><?php endif; ?>
		<button type="button" id="capw-lead">👨‍🔧 کارشناس</button>
	</div>
	<form class="capw-in" id="capw-form"><input type="text" id="capw-q" placeholder="نام قطعه یا خودرو را بنویسید…" autocomplete="off"><button aria-label="ارسال"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/></svg></button></form>
</div>
</div>
<script>
(function(){
var API=<?php echo wp_json_encode( $cfg['api'] ); ?>,root=document.getElementById('capw-root'),box=document.getElementById('capw'),btn=document.getElementById('capw-btn'),hi=document.getElementById('capw-hi'),msgs=document.getElementById('capw-msgs'),started=false;
try{var ff=getComputedStyle(document.body).fontFamily;if(ff)root.style.setProperty('--f',ff)}catch(e){}
function el(t,c,x){var e=document.createElement(t);if(c)e.className=c;if(x!=null)e.textContent=x;return e}
function scroll(){msgs.scrollTop=msgs.scrollHeight}
function hm(){var d=new Date();return ('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2)}
function bubble(t,me){var b=el('div','capw-b '+(me?'capw-me':'capw-bot'),t);b.appendChild(el('span','capw-t',hm()));msgs.appendChild(b);scroll()}
function typing(){var b=el('div','capw-b capw-bot capw-typing');b.appendChild(el('i'));b.appendChild(el('i'));b.appendChild(el('i'));msgs.appendChild(b);scroll();return b}
function call(p,cb){
  var loading=typing(),t0=Date.now();
  fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)})
  .then(function(r){return r.json()})
  .then(function(d){setTimeout(function(){loading.remove();render(d);if(cb)cb(d)},Math.max(0,450-(Date.now()-t0)))})
  .catch(function(){loading.remove();bubble('⚠️ خطا در ارتباط؛ دوباره تلاش کنید.',false)});
}
function chips(list,cls){
  if(!list||!list.length)return;var w=el('div','capw-chips');
  list.forEach(function(c,n){
    var x;
    if(c.u){x=el('a','capw-chip',c.t);x.href=c.u;if(c.u.indexOf('tel:')!==0)x.target='_blank';x.rel='noopener'}
    else{x=el('button','capw-chip',c.t);x.type='button';x.onclick=function(){bubble(c.t,true);act(c)}}
    x.style.animationDelay=(n*50)+'ms';w.appendChild(x)});
  msgs.appendChild(w);scroll()
}
function tiles(list){
  var g=el('div','capw-tiles');
  list.forEach(function(k){
    var b=el('button','capw-tile');b.type='button';b.appendChild(el('i',null,k.i));b.appendChild(el('span',null,k.t));
    b.onclick=function(){bubble(k.i+' '+k.t,true);act(k)};g.appendChild(b)});
  msgs.appendChild(g);scroll()
}
function act(c){
  if(c.a==='leadform'){leadform(c.n||'');return}
  call({a:c.a,id:c.id,m:c.m,p:c.p});
}
function render(d){
  if(d.text)bubble(d.text,false);
  if(d.tiles)tiles(d.tiles);
  (d.cards||[]).forEach(function(k,n){
    var a=el('a','capw-card');a.href=k.url;a.target='_blank';a.rel='noopener';a.style.animationDelay=(n*70)+'ms';
    if(k.off>0)a.appendChild(el('u',null,k.off+'٪ تخفیف'));
    if(k.img){var i=el('img');i.src=k.img;i.alt='';i.loading='lazy';a.appendChild(i)}
    var s=el('div');s.appendChild(el('span',null,k.title));if(k.price)s.appendChild(el('em',null,k.price));a.appendChild(s);msgs.appendChild(a)});
  var l=(d.chips||[]).slice();
  if(d.more)l.push({t:'▶️ نمایش بیشتر',a:d.more.a,id:d.more.id,m:d.more.m,p:d.more.p});
  if(!d.home)l.push({t:'🏠 منوی اصلی',a:'home'});
  chips(l);
  if(d.suggest&&d.suggest.length){msgs.appendChild(el('div','capw-sug','🔥 پیشنهاد شروع سریع:'));chips(d.suggest.map(function(x){return{t:'🚘 '+x.t,a:'cat',id:x.id}}))}
  scroll();
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
document.getElementById('capw-lead').onclick=function(){leadform('')};
document.getElementById('capw-form').onsubmit=function(e){e.preventDefault();var q=document.getElementById('capw-q');var v=q.value.trim();if(!v)return;bubble(v,true);q.value='';call({a:'search',q:v})};
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&box.classList.contains('open'))close()});
try{if(!sessionStorage.getItem('capw_hi')){setTimeout(function(){if(!box.classList.contains('open')){hi.classList.add('show');sessionStorage.setItem('capw_hi','1');setTimeout(hideHi,9000)}},4000)}}catch(e){}
})();
</script>
		<?php
	}
}
