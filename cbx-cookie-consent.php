<?php
/**
 * Plugin Name: CHIROBASIX - Cookie Consent Banner
 * Plugin URI:  https://github.com/CHIROBASIX-LLC/cbx-plugin-cookie-consent
 * GitHub Repo: CHIROBASIX-LLC/cbx-plugin-cookie-consent
 * Description: Cookie consent banner with Google Consent Mode v2. Holds Google tags until the visitor chooses, and exposes dataLayer events so Google Tag Manager can gate non-Google tags such as the Meta Pixel. Design and wording are editable under Settings, Cookie Consent. No third-party service, no subscription, no external requests.
 * Version:     1.0.0
 * Author:      CHIROBASIX
 * Author URI:  https://chirobasix.com
 * License:     GPL-2.0+
 * Text Domain: cbx-cookie-consent
 *
 * HOW IT WORKS
 * ------------
 * 1. At wp_head priority 0 (before everything, including Elementor Custom Code, which prints at
 *    wp_head priority 10) this plugin defines window.gtag and issues the Consent Mode v2 `default`
 *    command. Google tags loaded later read that state and refuse to write cookies until it says
 *    granted.
 * 2. At wp_footer it prints a self-contained banner. Accept / Decline issue the Consent Mode
 *    `update` command and push dataLayer events.
 * 3. Non-Google tags ignore Consent Mode entirely, so gate those in GTM on the Custom Event
 *    trigger `cbx_consent_marketing_granted`.
 *
 * CACHE SAFETY: every visitor is served byte-identical HTML. All decisions happen in the browser.
 * Never wrap a tag snippet in a PHP `if` that reads the consent cookie: WP Engine and Cloudflare
 * will bake one visitor's answer into the cached page and serve it to everyone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBXCC_VERSION', '1.0.0' );
define( 'CBXCC_FILE', __FILE__ );
define( 'CBXCC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CBXCC_OPTION', 'cbxcc_settings' );
define( 'CBXCC_GITHUB_OWNER', 'CHIROBASIX-LLC' );
define( 'CBXCC_GITHUB_REPO', 'cbx-plugin-cookie-consent' );

require_once CBXCC_DIR . 'includes/class-cbxcc-updater.php';
require_once CBXCC_DIR . 'includes/class-cbxcc-settings.php';
require_once CBXCC_DIR . 'includes/class-cbxcc-log.php';

new CBXCC_Updater( CBXCC_FILE, CBXCC_GITHUB_OWNER, CBXCC_GITHUB_REPO );
new CBXCC_Settings();
new CBXCC_Log();

register_activation_hook( __FILE__, array( 'CBXCC_Log', 'create_table' ) );

/**
 * Defaults. Everything here is editable under Settings, Cookie Consent; these are only the
 * starting values and the fallback if a setting has never been saved.
 */
function cbxcc_defaults() {
	return array(
		'enabled'             => 1,

		// Design.
		'position'            => 'bottom-left',
		'offset'              => 16,
		'radius'              => 6,
		'max_width'           => 430,
		'bg'                  => '#ffffff',
		'fg'                  => '#1a1a1a',
		'muted'               => '#5a5a5a',
		'border'              => '#d5d5d5',
		'accept_bg'           => '#1f6f43',
		'accept_fg'           => '#ffffff',
		'reject_bg'           => '#e8e8e8',
		'reject_fg'           => '#1a1a1a',

		// Text.
		'title'               => 'Cookies on this site',
		'body'                => 'We use cookies to understand how the site is used. You can accept or decline. Declining will not affect how the site works.',
		'accept_label'        => 'Accept',
		'reject_label'        => 'Decline',
		'prefs_label'         => 'Choose what to allow',
		'save_label'          => 'Save choices',
		'policy_url'          => '/cookie-policy/',
		'policy_label'        => 'Cookie Policy',
		'cat_necessary'       => 'Necessary',
		'cat_necessary_desc'  => 'Needed for the site to load, keep you secure and remember your accessibility settings. Always on.',
		'cat_analytics'       => 'Analytics',
		'cat_analytics_desc'  => 'Counts visits and which pages are read, so we can see what is useful. Never used to advertise to you.',
		'cat_marketing'       => 'Advertising',
		'cat_marketing_desc'  => 'Lets advertising platforms recognise this browser across other websites.',

		// Behaviour.
		'default_outside_eu'  => 'granted',
		'remember_days'       => 180,
		'policy_version'      => 1,

		// Consent log.
		'logging'             => 1,
		'log_ip'              => 'none',
		'log_retention_days'  => 365,
	);
}

function cbxcc_settings() {
	$saved = get_option( CBXCC_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return apply_filters( 'cbxcc_settings', wp_parse_args( $saved, cbxcc_defaults() ) );
}

function cbxcc_get( $key ) {
	$s = cbxcc_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/**
 * Announce this plugin as the site's consent manager for the WP Consent API standard, so
 * consent-aware plugins such as Google Site Kit and WooCommerce follow this banner instead of
 * each shipping its own. Harmless no-op when that plugin is not installed.
 */
add_filter( 'wp_get_consent_type', 'cbxcc_consent_type' );
function cbxcc_consent_type() {
	return 'optin';
}

function cbxcc_is_active() {
	if ( is_admin() || ! cbxcc_get( 'enabled' ) ) {
		return false;
	}
	return (bool) apply_filters( 'cbxcc_enabled', true );
}

/**
 * The consent signal. Printed inline and synchronously, as early as possible, because Consent
 * Mode's `default` command is only meaningful if it runs BEFORE the tag that reads it.
 */
function cbxcc_print_head() {
	if ( ! cbxcc_is_active() ) {
		return;
	}

	$s      = cbxcc_settings();
	$js_cfg = wp_json_encode(
		array(
			'v'    => (string) $s['policy_version'],
			'days' => (int) $s['remember_days'],
			'out'  => ( 'granted' === $s['default_outside_eu'] ) ? 1 : 0,
			'log'  => $s['logging'] ? esc_url_raw( rest_url( 'cbx-consent/v1/log' ) ) : '',
		)
	);
	?>
<!-- CHIROBASIX Cookie Consent: Consent Mode v2 defaults -->
<script>(function(w,d){
var C=<?php echo $js_cfg; // phpcs:ignore WordPress.Security.EscapeOutput ?>,NAME='cbx_consent';
w.dataLayer=w.dataLayer||[];
function gtag(){w.dataLayer.push(arguments);}
w.gtag=gtag;

function read(){
  var m=d.cookie.match(/(?:^|;\s*)cbx_consent=([^;]*)/);
  if(!m){return null;}
  try{var o=JSON.parse(decodeURIComponent(m[1]));return (o&&String(o.v)===String(C.v))?o:null;}catch(e){return null;}
}

/* Region is decided in the BROWSER from the IANA timezone, never on the server. Server-side geo
   would be baked into the page cache and served to everyone. Over-inclusive on purpose: anything
   that errors or looks European is treated as opt-in. */
function isEurope(){
  try{
    var tz=(Intl.DateTimeFormat().resolvedOptions().timeZone)||'';
    return /^Europe\//.test(tz)||/^Atlantic\/(Azores|Madeira|Canary|Faroe|Reykjavik)$/.test(tz);
  }catch(e){return true;}
}

var stored=read(),eu=isEurope(),a,m;
if(stored){a=!!stored.a;m=!!stored.m;}
else if(eu){a=false;m=false;}
else{a=!!C.out;m=!!C.out;}

function signal(an,mk){
  return {
    ad_storage:mk?'granted':'denied',
    ad_user_data:mk?'granted':'denied',
    ad_personalization:mk?'granted':'denied',
    personalization_storage:mk?'granted':'denied',
    analytics_storage:an?'granted':'denied',
    functionality_storage:'granted',
    security_storage:'granted'
  };
}

gtag('consent','default',signal(a,m));

/* Replay the decision as dataLayer events so GTM can fire non-Google tags on THIS pageview, not
   only on the page where the visitor clicked Accept. */
if(a){w.dataLayer.push({event:'cbx_consent_analytics_granted'});}
if(m){w.dataLayer.push({event:'cbx_consent_marketing_granted'});}
w.dataLayer.push({event:'cbx_consent_ready',cbx_analytics:a?1:0,cbx_marketing:m?1:0,cbx_decided:stored?1:0,cbx_region:eu?'eu':'row'});

w.cbxConsent={version:C.v,region:eu?'eu':'row',decided:!!stored,analytics:a,marketing:m,
  state:function(){return {analytics:this.analytics,marketing:this.marketing,decided:this.decided,region:this.region};},
  _signal:signal,_name:NAME,_days:C.days,_log:C.log,_stored:stored};
})(window,document);</script>
<!-- /CHIROBASIX Cookie Consent -->
	<?php
}
add_action( 'wp_head', 'cbxcc_print_head', 0 );

/**
 * The banner. Printed for everyone, hidden by default, revealed by JavaScript only when there is
 * no stored decision. Identical markup for every visitor is what makes this safe behind a cache.
 */
function cbxcc_print_footer() {
	if ( ! cbxcc_is_active() ) {
		return;
	}

	$s   = cbxcc_settings();
	$off = (int) $s['offset'];
	$mw  = (int) $s['max_width'];

	$pos = "left:{$off}px;right:auto;max-width:min({$mw}px,calc(100vw - " . ( $off * 2 ) . 'px));';
	if ( 'bottom-right' === $s['position'] ) {
		$pos = "right:{$off}px;left:auto;max-width:min({$mw}px,calc(100vw - " . ( $off * 2 ) . 'px));';
	} elseif ( 'bottom-bar' === $s['position'] ) {
		$pos = "left:{$off}px;right:{$off}px;max-width:none;";
	} elseif ( 'bottom-center' === $s['position'] ) {
		$pos = "left:50%;right:auto;transform:translateX(-50%);max-width:min({$mw}px,calc(100vw - " . ( $off * 2 ) . 'px));';
	}
	?>
<!-- CHIROBASIX Cookie Consent: banner -->
<style id="cbxcc-css">
.cbxcc{position:fixed;bottom:<?php echo esc_attr( $off ); ?>px;<?php echo esc_attr( $pos ); ?>
  z-index:2147483000;background:<?php echo esc_attr( $s['bg'] ); ?>;color:<?php echo esc_attr( $s['fg'] ); ?>;
  border:1px solid <?php echo esc_attr( $s['border'] ); ?>;border-radius:<?php echo esc_attr( (int) $s['radius'] ); ?>px;
  box-shadow:0 6px 28px rgba(0,0,0,.16);padding:18px 20px;
  font:400 15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;}
.cbxcc[hidden]{display:none!important}
.cbxcc h2{margin:0 0 6px;font-size:16px;font-weight:600;line-height:1.3;color:inherit}
.cbxcc p{margin:0 0 14px;color:<?php echo esc_attr( $s['muted'] ); ?>;font-size:14px}
.cbxcc a{color:inherit;text-decoration:underline}
.cbxcc-row{display:flex;flex-wrap:wrap;gap:8px}
.cbxcc-btn{font:inherit;font-size:14px;font-weight:600;padding:10px 18px;
  border-radius:<?php echo esc_attr( max( 0, (int) $s['radius'] - 2 ) ); ?>px;
  border:1px solid transparent;cursor:pointer;flex:1 1 0;min-width:120px;text-align:center}
.cbxcc-accept{background:<?php echo esc_attr( $s['accept_bg'] ); ?>;color:<?php echo esc_attr( $s['accept_fg'] ); ?>}
.cbxcc-reject{background:<?php echo esc_attr( $s['reject_bg'] ); ?>;color:<?php echo esc_attr( $s['reject_fg'] ); ?>;
  border-color:<?php echo esc_attr( $s['border'] ); ?>}
.cbxcc-link{background:none;border:0;padding:6px 0 0;font:inherit;font-size:13px;
  color:<?php echo esc_attr( $s['muted'] ); ?>;text-decoration:underline;cursor:pointer;flex:0 0 100%;text-align:left}
.cbxcc-btn:focus-visible,.cbxcc-link:focus-visible,.cbxcc input:focus-visible{outline:2px solid <?php echo esc_attr( $s['accept_bg'] ); ?>;outline-offset:2px}
.cbxcc-prefs{margin:0 0 14px;padding:12px 0 2px;border-top:1px solid <?php echo esc_attr( $s['border'] ); ?>}
.cbxcc-prefs[hidden]{display:none!important}
.cbxcc-opt{display:grid;grid-template-columns:auto 1fr;gap:4px 10px;margin-bottom:12px}
.cbxcc-opt input{margin:3px 0 0;width:16px;height:16px;accent-color:<?php echo esc_attr( $s['accept_bg'] ); ?>}
.cbxcc-opt label{font-size:14px;font-weight:600;cursor:pointer}
.cbxcc-opt span{grid-column:2;font-size:13px;color:<?php echo esc_attr( $s['muted'] ); ?>;line-height:1.45}
.cbxcc-opt input:disabled+label{cursor:default;opacity:.75}
@media (prefers-reduced-motion: no-preference){
  .cbxcc{animation:cbxcc-in .28s ease-out}
  @keyframes cbxcc-in{from{opacity:0}to{opacity:1}}
}
</style>

<div class="cbxcc" id="cbxcc" role="dialog" aria-modal="false" aria-labelledby="cbxcc-t" aria-describedby="cbxcc-d" tabindex="-1" hidden>
  <h2 id="cbxcc-t"><?php echo esc_html( $s['title'] ); ?></h2>
  <p id="cbxcc-d"><?php echo esc_html( $s['body'] ); ?>
    <?php if ( ! empty( $s['policy_url'] ) ) : ?>
    <a href="<?php echo esc_url( $s['policy_url'] ); ?>"><?php echo esc_html( $s['policy_label'] ); ?></a>
    <?php endif; ?></p>

  <div class="cbxcc-prefs" id="cbxcc-prefs" hidden>
    <div class="cbxcc-opt">
      <input type="checkbox" id="cbxcc-nec" checked disabled>
      <label for="cbxcc-nec"><?php echo esc_html( $s['cat_necessary'] ); ?></label>
      <span><?php echo esc_html( $s['cat_necessary_desc'] ); ?></span>
    </div>
    <div class="cbxcc-opt">
      <input type="checkbox" id="cbxcc-ana">
      <label for="cbxcc-ana"><?php echo esc_html( $s['cat_analytics'] ); ?></label>
      <span><?php echo esc_html( $s['cat_analytics_desc'] ); ?></span>
    </div>
    <div class="cbxcc-opt">
      <input type="checkbox" id="cbxcc-mkt">
      <label for="cbxcc-mkt"><?php echo esc_html( $s['cat_marketing'] ); ?></label>
      <span><?php echo esc_html( $s['cat_marketing_desc'] ); ?></span>
    </div>
  </div>

  <div class="cbxcc-row">
    <button type="button" class="cbxcc-btn cbxcc-accept" id="cbxcc-yes"><?php echo esc_html( $s['accept_label'] ); ?></button>
    <button type="button" class="cbxcc-btn cbxcc-reject" id="cbxcc-no"><?php echo esc_html( $s['reject_label'] ); ?></button>
    <button type="button" class="cbxcc-btn cbxcc-accept" id="cbxcc-save" hidden><?php echo esc_html( $s['save_label'] ); ?></button>
    <button type="button" class="cbxcc-link" id="cbxcc-more"><?php echo esc_html( $s['prefs_label'] ); ?></button>
  </div>
</div>

<script>(function(w,d){
var API=w.cbxConsent; if(!API){return;}
var box=d.getElementById('cbxcc'),prefs=d.getElementById('cbxcc-prefs'),
    ana=d.getElementById('cbxcc-ana'),mkt=d.getElementById('cbxcc-mkt'),
    yes=d.getElementById('cbxcc-yes'),no=d.getElementById('cbxcc-no'),
    save=d.getElementById('cbxcc-save'),more=d.getElementById('cbxcc-more');

function uuid(){
  try{if(w.crypto&&w.crypto.randomUUID){return w.crypto.randomUUID();}}catch(e){}
  return 'c-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,10);
}

function store(a,m,id){
  var o={v:API.version,t:new Date().toISOString(),a:a?1:0,m:m?1:0,id:id},
      exp=new Date(Date.now()+API._days*864e5).toUTCString();
  d.cookie=API._name+'='+encodeURIComponent(JSON.stringify(o))+
    ';expires='+exp+';path=/;SameSite=Lax'+(location.protocol==='https:'?';Secure':'');
}

/* A Decline that leaves _ga and _fbp sitting in the jar is not a decline. Clear them on the bare
   host, the dot-host and the registrable domain, since tags set them differently. First-party
   names only: a cookie on .facebook.com is not ours to delete. */
function clearTracking(){
  var kill=/^(_ga|_gid|_gat|_gcl_|__utm|_fbp|_fbc|_ttp|_tt_enable_cookie|_rdt_uuid|_uetsid|_uetvid|_clck|_clsk|_scid|_pin_unauth|_hj)/,
      h=location.hostname,p=h.split('.'),doms=[h,'.'+h];
  if(p.length>2){var r=p.slice(-2).join('.');doms.push(r,'.'+r);}
  d.cookie.split(';').forEach(function(c){
    var n=c.split('=')[0].trim(); if(!n||!kill.test(n)){return;}
    d.cookie=n+'=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
    doms.forEach(function(dm){
      d.cookie=n+'=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;domain='+dm;
    });
  });
}

/* Mirror the decision into the WP Consent API categories so consent-aware plugins follow it.
   Guarded: the function only exists when that plugin is active. */
function syncWpConsentApi(a,m){
  if(typeof w.wp_set_consent!=='function'){return;}
  try{
    w.wp_set_consent('functional','allow');
    w.wp_set_consent('preferences','allow');
    w.wp_set_consent('statistics',a?'allow':'deny');
    w.wp_set_consent('statistics-anonymous',a?'allow':'deny');
    w.wp_set_consent('marketing',m?'allow':'deny');
  }catch(e){}
}

/* Record the choice for the site owner's audit trail. Fire and forget: a failed log must never
   affect what the visitor sees or whether their choice is honoured. */
function logChoice(a,m,id){
  if(!API._log){return;}
  try{
    var body=JSON.stringify({id:id,analytics:a?1:0,marketing:m?1:0,
      version:API.version,region:API.region,url:location.pathname});
    if(w.navigator&&navigator.sendBeacon){
      navigator.sendBeacon(API._log,new Blob([body],{type:'application/json'}));
    }else{
      fetch(API._log,{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true}).catch(function(){});
    }
  }catch(e){}
}

function apply(a,m){
  var wasA=API.analytics,wasM=API.marketing,id=uuid();
  API.analytics=a; API.marketing=m; API.decided=true;
  store(a,m,id);
  w.gtag('consent','update',API._signal(a,m));
  w.dataLayer.push({event:'cbx_consent_update',cbx_analytics:a?1:0,cbx_marketing:m?1:0});
  if(a&&!wasA){w.dataLayer.push({event:'cbx_consent_analytics_granted'});}
  if(m&&!wasM){w.dataLayer.push({event:'cbx_consent_marketing_granted'});}
  if((wasA&&!a)||(wasM&&!m)){clearTracking();}
  syncWpConsentApi(a,m);
  logChoice(a,m,id);
  hide();
}

function show(focus){
  ana.checked=API.analytics; mkt.checked=API.marketing;
  box.hidden=false;
  if(focus){box.focus();}
}
function hide(){
  box.hidden=true; prefs.hidden=true; save.hidden=true;
  yes.hidden=false; no.hidden=false; more.hidden=false;
}

yes.addEventListener('click',function(){apply(true,true);});
no.addEventListener('click',function(){apply(false,false);});
save.addEventListener('click',function(){apply(ana.checked,mkt.checked);});
more.addEventListener('click',function(){
  prefs.hidden=false; save.hidden=false;
  yes.hidden=true; no.hidden=true; more.hidden=true;
  ana.focus();
});

/* Escape closes the preferences panel but never the banner. Dismissing without choosing is not a
   decision, and treating it as one is the whole reason a plain popup cannot do this job. */
box.addEventListener('keydown',function(e){
  if(e.key==='Escape'&&!prefs.hidden){
    prefs.hidden=true;save.hidden=true;yes.hidden=false;no.hidden=false;more.hidden=false;yes.focus();
  }
});

/* Re-open path. Any link to #cookie-settings or with class .cbx-consent-open works. */
API.open=function(){show(true);};
d.addEventListener('click',function(e){
  var t=e.target.closest?e.target.closest('a[href="#cookie-settings"],a[href$="#cookie-settings"],.cbx-consent-open'):null;
  if(t){e.preventDefault();API.open();}
});

syncWpConsentApi(API.analytics,API.marketing);
if(!API.decided){show(false);}
})(window,document);</script>
<!-- /CHIROBASIX Cookie Consent -->
	<?php
}
add_action( 'wp_footer', 'cbxcc_print_footer', 20 );
