<?php

class DrupalREST
{
	var $crl;
	var $token;
	var $url;

	function __construct( $site, $user, $pass )
	{
		$this->url = $site;
		$this->crl = curl_init();

		curl_setopt($this->crl, CURLOPT_VERBOSE, 1);

		curl_setopt($this->crl, CURLOPT_SSL_VERIFYPEER, 0);

		curl_setopt($this->crl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($this->crl, CURLOPT_USERAGENT, 'PHP script');
		curl_setopt($this->crl, CURLOPT_COOKIEJAR, "/tmp/cookie.txt");
		curl_setopt($this->crl, CURLOPT_COOKIEFILE, '/tmp/cookie.txt');

		// Login
		curl_setopt($this->crl, CURLOPT_URL, $this->url . "/user/login");
		curl_setopt($this->crl, CURLOPT_POST, TRUE);
		curl_setopt($this->crl, CURLOPT_POSTFIELDS, "name=" . $user . "&pass=" . $pass . "&form_id=user_login");
		curl_setopt($this->crl, CURLOPT_COOKIE, session_name() . '=' . session_id());
		$ret = new stdClass;
		$ret->response = $this->curl_exec($this->crl);
		$ret->error = curl_error($this->crl);
		$ret->info = curl_getinfo($this->crl);
		if( $ret->error ) { print "LOGIN: ".$ret->error."\n"; }
		
		// Get RESTWS token.
		curl_setopt($this->crl, CURLOPT_HTTPGET, TRUE);
		curl_setopt($this->crl, CURLOPT_URL, $this->url . '/restws/session/token');
		$ret = new stdClass;
		$ret->response = $this->curl_exec($this->crl);
		$ret->error = curl_error($this->crl);
		$ret->info = curl_getinfo($this->crl);
		$this->token = $ret->response;
		if( $ret->info["http_code"] != '200' ) { print "FAILED to get token: ".$ret->info["http_code"]."\n"; }
		if( $ret->error ) { print "GETTOKEN: ".$ret->error."\n"; }
	}

	function get_nodes($filter="")
	{
		# start with a fake response 
		$results = array( "next"=>$this->url."/node?".$filter );
		$nodes = array();
 		while( @$results['next'] )
		{
			$url = $results['next'];
			curl_setopt($this->crl, CURLOPT_URL, $url );
			curl_setopt($this->crl, CURLOPT_HTTPGET, TRUE);
			curl_setopt($this->crl, CURLOPT_HTTPHEADER, array('Accept: application/json', 'X-CSRF-Token: ' . $this->token));
			$response = $this->curl_exec($this->crl);
			$results = json_decode( $response, true );
			foreach( $results['list'] as $item ) { $nodes []= $item; }
		}
		return $nodes;
	}

	function node_create( $data )
	{	
		curl_setopt($this->crl, CURLOPT_POST, TRUE);
		curl_setopt($this->crl, CURLOPT_CUSTOMREQUEST, "POST");
		
		curl_setopt($this->crl, CURLOPT_URL, $this->url . '/node');
		
		curl_setopt($this->crl, CURLOPT_HTTPGET, FALSE);
		curl_setopt($this->crl, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($this->crl, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'X-CSRF-Token: ' . $this->token));
		$ret = new stdClass;
		$ret->response = $this->curl_exec($this->crl);
		$ret->error = curl_error($this->crl);
		$ret->info = curl_getinfo($this->crl);
		if( $ret->error ) { print "CREATE: ".$ret->error."\n"; die( "DNAG"); }
		if( $ret->info["http_code"] != '200' ) { print "CREATE: ".$ret->error."\n"; }
		return $ret;
	}

	function node_update( $url, $data )
	{	
		curl_setopt($this->crl, CURLOPT_POST, FALSE);
		curl_setopt($this->crl, CURLOPT_CUSTOMREQUEST, "PUT");
		
		curl_setopt($this->crl, CURLOPT_URL, $url );
		
		curl_setopt($this->crl, CURLOPT_HTTPGET, FALSE);
		curl_setopt($this->crl, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($this->crl, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'X-CSRF-Token: ' . $this->token));
	
		$ret = new stdClass;
		$ret->response = $this->curl_exec($this->crl);
		$ret->error = curl_error($this->crl);
		$ret->info = curl_getinfo($this->crl);
		if( $ret->error ) { print "UPDATE: ".$ret->error."\n"; }
		if( $ret->info["http_code"] != '200' ) { print "UPDATE: ".$ret->error."\n"; }
	}

	function close()
	{
		curl_close ($this->crl);
		unset($this->crl);
	}

	function opt_req( $fn, $opts, $field )
	{
		if( !array_key_exists( $field, $opts ))
		{
			die( "DrupalRest->$fn missing required field '$field'\n");
		}
	}
	function opt_default( &$opts, $field, $default)
	{
		if( !array_key_exists( $field, $opts ))
		{
			$opts[ $field ] = $default;
		}
	}

	static function record_update( $node, $record )
	{
		$data = array();
		$data["field_data"] = json_encode( $record );
		return $data;
	}

	static function record_create( $record )
	{
		$data = array();
		$data["title"] = "New node";
		$data["field_data"] = json_encode( $record );
		return $data;
	}

	function sync($opts)
	{
		$this->opt_req( "sync",$opts,"id_field" ); # string
		$this->opt_req( "sync",$opts,"content_type" ); # string
		$this->opt_req( "sync",$opts,"records" ); # array
		$this->opt_default( $opts, "force", 0 ); # bool
		$this->opt_default( $opts, "update", "DrupalRest::record_update" );
		$this->opt_default( $opts, "create", "DrupalRest::record_create" );
		if( !@$opts["force"] && sizeof( $opts["records"])<= 0 )
		{
			die( "DrupalRest->sync with 0 records. Won't sync without force option\n" );
		}
		$nodes = $this->get_nodes( "type=".$opts["content_type"]);

		$nodes_by_id = array();
		foreach( $nodes as $node )
		{
			$id=$node[$opts["id_field"]];
			if( $id != "" )
			{
				$nodes_by_id[ $id ] = $node;
			}
		}

		foreach( $nodes_by_id as $id=>$node )
		{
			if( array_key_exists( $id, $opts["records"] ) )
			{
				$data = call_user_func( $opts["update"], $node, $opts["records"][$id] );
				$data["status"] = 1; // published
				$result = $this->node_update( $this->url."/node/".$node["nid"], $data );
			}
			else
			{
				print "** $id **\n";
				if( $node["status"] ) 
				{
					// expire only if not already expired
					$data = array( "status"=> 0 ); // unpublished
					$result = $this->node_update( $this->url."/node/".$node["nid"], $data );
				}
			}
		}

		foreach( $opts["records"] as $id=>$record )
		{
			if( !array_key_exists( $id, $nodes_by_id ) )
			{
				// create may set fields which are not auto 
				// updated afterwards.
				$data = call_user_func( $opts["create"], $record );
				$data[ $opts["id_field"] ] = $id;
				$data[ "type" ] = $opts["content_type"];
				//$data[ "language" ] = "und";
				$data[ "status" ] = 1;
				$result = $this->node_create( $data );
			}
		}	
	}

	function curl_exec($crl)
	{
		$result = curl_exec( $crl );
		print "URL: ". curl_getinfo($crl, CURLINFO_EFFECTIVE_URL )." .. ". curl_getinfo($crl, CURLINFO_HTTP_CODE )."\n";
		return $result;
	}
}


