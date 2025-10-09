<?php
if ( ! defined('ABSPATH') ) exit;
add_filter('pre_set_site_transient_update_plugins', function($t){
  if(empty($t->checked)) return $t;
  $plugin=plugin_basename(dirname(__FILE__,2).'/soundwave.php');
  $cur=SOUNDWAVE_VERSION;
  $r=wp_remote_get('https://api.github.com/repos/emkowale/soundwave/releases', array('timeout'=>10,'headers'=>array('Accept'=>'application/vnd.github+json')));
  if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200) return $t;
  $rels=json_decode(wp_remote_retrieve_body($r),true); if(!is_array($rels)) return $t;
  $latest=null; foreach($rels as $rel){ if(!empty($rel['tag_name']) && preg_match('/^v?\d+\.\d+\.\d+$/',$rel['tag_name'])){ if(!$latest || version_compare(ltrim($rel['tag_name'],'v'), ltrim($latest['tag_name'],'v'), '>')) $latest=$rel; } }
  if(!$latest) return $t; $new=ltrim($latest['tag_name'],'v'); if(version_compare($new,$cur,'<=')) return $t;
  $o=new stdClass(); $o->slug='soundwave'; $o->plugin=$plugin; $o->new_version=$new; $o->url='https://github.com/emkowale/soundwave'; $o->package=$latest['zipball_url']; $t->response[$plugin]=$o; return $t;
});
add_filter('plugins_api', function($res,$action,$args){ if($action!=='plugin_information'||empty($args->slug)||$args->slug!=='soundwave') return $res; $i=new stdClass(); $i->name='Soundwave'; $i->slug='soundwave'; $i->version=SOUNDWAVE_VERSION; $i->author='Eric Kowalewski'; $i->homepage='https://github.com/emkowale/soundwave'; $i->sections=array('description'=>'Push WooCommerce orders to thebeartraxs.com hub.','changelog'=>'See GitHub Releases.'); return $i; },10,3);
