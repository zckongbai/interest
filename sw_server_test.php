<?php

class Server
{
    private $serv;

    public function __construct() {
        $this->serv = new swoole_server("0.0.0.0", 9509);
        $this->serv->set(array(
            'worker_num' => 8,
            'daemonize' => false,
            'max_request' => 10000,
            'dispatch_mode' => 2,
            'debug_mode'=> 1,
        ));

        $this->serv->on('Start', array($this, 'onStart'));
        $this->serv->on('Connect', array($this, 'onConnect'));
        $this->serv->on('Receive', array($this, 'onReceive'));
        $this->serv->on('Close', array($this, 'onClose'));

        $this->table = new swoole_table(1024);
        $this->table->column('fd', swoole_table::TYPE_INT, 4);       //1,2,4,8
        $this->table->column('name', swoole_table::TYPE_STRING, 64);
        $this->table->column('num', swoole_table::TYPE_FLOAT);
        $this->table->create();

        $this->serv->start();
    }

    public function onStart( $serv ) {
        echo "Start\n";
    }

    public function onConnect( $serv, $fd, $from_id ) {
        echo "Client {$fd} connect\n";
        $res = $this->table->set($fd, array("fd" => $fd, "name" => "abc", "num" =>$from_id));
        var_dump($res);
    }

    public function onReceive( swoole_server $serv, $fd, $from_id, $data ) {
        $data = json_decode($data,true);
        // send a task to task worker.
        $data['fd'] = $fd;
        // $serv->task( json_encode($data) );

        echo "Continue Handle Worker\n";
    }

    public function onClose( $serv, $fd, $from_id ) {
        echo "Client {$fd} close connection\n";
        var_dump($this->table->get($fd));
    }


}

$server = new Server();
