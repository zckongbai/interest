<?php

class Client
{
	private $client;

	public function __construct() {
	$this->client = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);

	$this->client->on('Connect', array($this, 'onConnect'));
	$this->client->on('Receive', array($this, 'onReceive'));
	$this->client->on('Close', array($this, 'onClose'));
	$this->client->on('Error', array($this, 'onError'));
	}
	
	public function connect() {
		$fp = $this->client->connect("127.0.0.1", 9500 , 1);
		if( !$fp ) {
			echo "Error: {$fp->errMsg}[{$fp->errCode}]\n";
			return;
		}
	}

	public function onReceive( $cli, $data ) {
	    $data = json_decode($data, true);
	    echo "\n Get Message From Server:" . $data['msg'] . "\n";
	  }

	public function onConnect( $cli) {
		while (1) {
		  	fwrite(STDOUT, "Enter Now(1~9)-Status(up|down)-Want(1~9):");
		  	list($msg['now'], $msg['status'], $msg['want']) = explode("-", trim(fgets(STDIN)));
			if (empty($msg['status'])) {
				$msg['status'] = $msg['now'] < $msg['want'] ? "up" : "down";
			}
	    	$cli->send( json_encode($msg) );
		}
		// swoole_event_add(STDIN, function($fp){
		//     global $cli;
		//     fwrite(STDOUT, "Enter Now(1~9)-Status(up|down)-Want(1~9):");
		// 	list($msg['now'], $msg['status'], $msg['want']) = explode("-", trim(fgets(STDIN)));
		// 	if (empty($msg['status'])) {
		// 		$msg['status'] = $msg['now'] < $msg['want'] ? "up" : "down";
		// 	}
	 //    	$cli->send( json_encode($msg) );
		// });
	}

	public function onClose( $cli) {
		echo "Client close connection\n";
	}

	public function onError() {

	}

	public function send($data) {
		$this->client->send( $data );
	}

	public function isConnected() {
		return $this->client->isConnected();
	}
}

$cli = new Client();
$cli->connect();
