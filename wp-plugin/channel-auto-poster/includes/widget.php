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
		$cfg   = array(
			'api'   => esc_url_raw( rest_url( 'cap/v1/w' ) ),
			'title' => $s['widget_title'] ?: 'راهنمای خرید',
		);
		?>
<style>
#capw-btn{position:fixed;left:18px;bottom:18px;z-index:99998;display:flex;align-items:center;gap:8px;background:<?php echo esc_attr( $color ); ?>;color:#fff;border:0;border-radius:999px;padding:12px 18px;font:600 15px/1 Tahoma,Vazirmatn,sans-serif;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);direction:rtl;animation:capw-pulse 2.4s infinite}
#capw-btn:hover{filter:brightness(1.1)}
@keyframes capw-pulse{0%{box-shadow:0 0 0 0 rgba(37,99,235,.45)}70%{box-shadow:0 0 0 14px rgba(37,99,235,0)}100%{box-shadow:0 0 0 0 rgba(37,99,235,0)}}
#capw{position:fixed;left:18px;bottom:18px;z-index:99999;width:360px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100vh - 36px);display:none;flex-direction:column;background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.3);direction:rtl;font:14px/1.7 Tahoma,Vazirmatn,sans-serif;color:#222}
#capw.open{display:flex}
#capw header{background:<?php echo esc_attr( $color ); ?>;color:#fff;padding:12px 14px;display:flex;align-items:center;gap:8px}
#capw header b{flex:1;font-size:15px}
#capw header button{background:rgba(255,255,255,.2);border:0;color:#fff;border-radius:8px;width:30px;height:30px;cursor:pointer;font-size:16px}
#capw-msgs{flex:1;overflow-y:auto;padding:12px;background:#f3f4f6}
.capw-b{max-width:92%;padding:9px 12px;border-radius:14px;margin:6px 0;white-space:pre-wrap;word-wrap:break-word}
.capw-bot{background:#fff;border:1px solid #e5e7eb;border-bottom-right-radius:4px}
.capw-me{background:<?php echo esc_attr( $color ); ?>;color:#fff;margin-left:auto;border-bottom-left-radius:4px}
.capw-chips{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}
.capw-chip{background:#fff;border:1px solid <?php echo esc_attr( $color ); ?>;color:<?php echo esc_attr( $color ); ?>;border-radius:999px;padding:6px 12px;font:inherit;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block}
.capw-chip:hover{background:<?php echo esc_attr( $color ); ?>;color:#fff}
.capw-card{display:flex;gap:10px;align-items:center;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:8px;margin:6px 0;text-decoration:none;color:inherit}
.capw-card:hover{border-color:<?php echo esc_attr( $color ); ?>}
.capw-card img{width:56px;height:56px;object-fit:cover;border-radius:8px;background:#eee;flex:none}
.capw-card span{display:block;font-size:13px}.capw-card em{display:block;font-style:normal;color:#059669;font-weight:700;font-size:13px}
#capw form.capw-in{display:flex;gap:6px;padding:10px;border-top:1px solid #e5e7eb;background:#fff}
#capw form.capw-in input{flex:1;border:1px solid #d1d5db;border-radius:10px;padding:9px 10px;font:inherit;min-width:0}
#capw form.capw-in button{background:<?php echo esc_attr( $color ); ?>;color:#fff;border:0;border-radius:10px;padding:0 14px;cursor:pointer;font:inherit}
.capw-form{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px;margin:6px 0;display:flex;flex-direction:column;gap:6px}
.capw-form input{border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit}
.capw-form button{background:<?php echo esc_attr( $color ); ?>;color:#fff;border:0;border-radius:8px;padding:9px;cursor:pointer;font:inherit}
@media(max-width:480px){#capw{left:8px;bottom:8px;width:calc(100vw - 16px);height:calc(100vh - 16px);max-height:none}}
</style>
<button id="capw-btn" type="button" aria-label="چت‌بات"><span>🤖</span><span><?php echo esc_html( $cfg['title'] ); ?></span></button>
<div id="capw" role="dialog" aria-label="<?php echo esc_attr( $cfg['title'] ); ?>">
	<header><b>🤖 <?php echo esc_html( $cfg['title'] ); ?></b><button type="button" id="capw-home" title="منوی اصلی">🏠</button><button type="button" id="capw-x" title="بستن">✕</button></header>
	<div id="capw-msgs"></div>
	<form class="capw-in" id="capw-form"><input type="text" id="capw-q" placeholder="نام قطعه یا خودرو را بنویسید…" autocomplete="off"><button>ارسال</button></form>
</div>
<script>
(function(){
var API=<?php echo wp_json_encode( $cfg['api'] ); ?>,box=document.getElementById('capw'),btn=document.getElementById('capw-btn'),msgs=document.getElementById('capw-msgs'),started=false;
function el(t,c,x){var e=document.createElement(t);if(c)e.className=c;if(x!=null)e.textContent=x;return e}
function scroll(){msgs.scrollTop=msgs.scrollHeight}
function bubble(t,me){var b=el('div','capw-b '+(me?'capw-me':'capw-bot'),t);msgs.appendChild(b);scroll()}
function call(p,cb){
  var loading=el('div','capw-b capw-bot','…');msgs.appendChild(loading);scroll();
  fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)})
  .then(function(r){return r.json()}).then(function(d){loading.remove();render(d);if(cb)cb(d)})
  .catch(function(){loading.textContent='⚠️ خطا در ارتباط؛ دوباره تلاش کنید.'});
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
function open(){box.classList.add('open');btn.style.display='none';if(!started){started=true;call({a:'home'})}}
function close(){box.classList.remove('open');btn.style.display='flex'}
btn.onclick=open;document.getElementById('capw-x').onclick=close;
document.getElementById('capw-home').onclick=function(){call({a:'home'})};
document.getElementById('capw-form').onsubmit=function(e){e.preventDefault();var q=document.getElementById('capw-q');var v=q.value.trim();if(!v)return;bubble(v,true);q.value='';call({a:'search',q:v})};
})();
</script>
		<?php
	}
}
