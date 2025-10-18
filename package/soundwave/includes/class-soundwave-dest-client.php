<?php
/*
 * File: includes/class-soundwave-dest-client.php
 * Desc: Destination Woo REST checks with STRICT matching; treats trash as missing.
 */
if (!defined('ABSPATH')) exit;

class Soundwave_Dest_Client {
    private $base,$ck,$cs;
    public function __construct($base,$ck,$cs){
        $this->base=rtrim((string)$base,'/'); $this->ck=(string)$ck; $this->cs=(string)$cs;
    }
    private function get($path,$args=[]){
        $args['consumer_key']=$this->ck; $args['consumer_secret']=$this->cs;
        $url=add_query_arg($args,"{$this->base}/wp-json/wc/v3{$path}");
        $res=wp_remote_get($url,['timeout'=>15,'headers'=>['Accept'=>'application/json']]);
        if(is_wp_error($res)) return $res;
        $c=(int)wp_remote_retrieve_response_code($res);
        if($c<200||$c>=300) return new WP_Error('bad_status',"HTTP $c");
        $j=json_decode((string)wp_remote_retrieve_body($res),true);
        return is_array($j)?$j:new WP_Error('bad_json','Bad JSON');
    }
    /** Exact check by destination id; returns false if 404 OR status === "trash" */
    public function exists_by_id($dest_id){
        $id=(int)$dest_id; if($id<=0) return false;
        $u="{$this->base}/wp-json/wc/v3/orders/{$id}?consumer_key={$this->ck}&consumer_secret={$this->cs}";
        $r=wp_remote_get($u,['timeout'=>15,'headers'=>['Accept'=>'application/json']]);
        if(is_wp_error($r)) return false;
        $c=(int)wp_remote_retrieve_response_code($r);
        if($c<200||$c>=300) return false;
        $body=json_decode((string)wp_remote_retrieve_body($r),true);
        $status = isset($body['status']) ? (string)$body['status'] : '';
        if ($status === 'trash') return false; // treat trashed as missing
        return true;
    }
    /**
     * STRICT: search and only accept exact equality on order_key OR number.
     * Skips any results with status === "trash".
     * Returns [bool exists, int found_id]
     */
    public function exists_by_key_or_number_strict($order_key,$order_number){
        $ok = trim((string)$order_key);
        $on = trim((string)$order_number);
        foreach (array_filter([$ok,$on]) as $term){
            $rows = $this->get('/orders',['search'=>$term,'per_page'=>10,'orderby'=>'date','order'=>'desc']);
            if (is_wp_error($rows) || empty($rows)) continue;
            foreach ($rows as $row){
                if (isset($row['status']) && $row['status'] === 'trash') continue; // ignore trashed
                $rk = (string)($row['order_key'] ?? '');
                $rn = (string)($row['number'] ?? '');
                if (($ok && $rk === $ok) || ($on && $rn === $on)) {
                    return [true, (int)$row['id']];
                }
            }
        }
        return [false, 0];
    }
}
