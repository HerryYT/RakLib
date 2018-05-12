<?php

declare(strict_types=1);

namespace raklib\server;

use raklib\utils\InternetAddress;

class RemoteServerManager {
	/**
	 * Сервера
	 * @var float[] string (address) => float (unblock time) 
	 */
	public $servers = [];

	/** @var UDPServerSocket */
	public $internalSocket;
	public $externalSocket;
	/** @var SessionManager */
	public $sessionManager;

	public $reusableAddress;

	public function __construct(UDPServerSocket $externalSocket, 
								UDPServerSocket $internalSocket) {
		$this->externalSocket = $externalSocket;
		$this->internalSocket = $internalSocket;

		$this->reusableAddress = new InternetAddress('', 0);

		$this->registerServers();
	}

	public function setSessionManager(SessionManager $sessionManager) {
		$this->sessionManager = $sessionManager;
	}

	// Получаем главный сервер (или, наверное лучше сказать доступный)
	// К которому будет подключен новый игрок
	public function getMainServer() : RemoteServer{
		foreach ($this->servers as $server) {
			// Если сервер главный
			if ($server->main) return $server;
		}
		// TODO: Что делать, если не найден сервер,
		// к которому можно подключить игрока
		// Пока будем возвращать последний сервер
		return $server;
	}

	// Получаем информацию с сервера
	// И перенаправляем её классу сервера
	public function receiveStream() : bool{
		$address = $this->reusableAddress;

		// Получаем данные из сокета
		$len = $this->internalSocket->readPacket($buffer, $address->ip, $address->port);

		// Если данных нет
		// выходим из функции
		if($len === false){
			return false;
		}

		// Структура пакета:
		// id сервера
		// id пакета
		if ($buffer{1} != chr(0x07)) {
			echo('Пришел пакет с сервера '.$address->toString() . PHP_EOL);
			echo(substr(bin2hex($buffer), 0, 50) . PHP_EOL);
			echo PHP_EOL;
		}

		$serverId = ord($buffer{0});
		$this->servers[$serverId]->receiveStream($buffer);

		return true;
	}

	// TODO: Что делаем при отключении сервера
	public function closeServer($id) {

	}

	// Функция нужна для регистрации сервера
	// У каждого сервера есть id, ip, port, главный сервер или нет
	private function registerServer(int $id, string $ip, int $port, bool $isMain) : void{
		// Создаем экземпляр сервера и добавляем его в список серверов
		$address = new InternetAddress($ip, $port);
		$this->servers[$id] = new RemoteServer($this, 
											   $this->internalSocket, 
											   $this->externalSocket, 
											   $address, $id, $isMain);
	}

	private function registerServers() : void {
		$this->registerServer(0, '192.168.0.100', 19130, true);
	}

}