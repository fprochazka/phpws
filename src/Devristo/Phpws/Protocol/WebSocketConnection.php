<?php

namespace Devristo\Phpws\Protocol;

use Exception;
use React\EventLoop\LoopInterface;
use React\Socket\Connection;
use Zend\Http\Request;
use Psr\Log\LoggerInterface;



class WebSocketConnection extends Connection
{

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * @var WebSocketTransportInterface
	 */
	private $transport = NULL;

	private $lastChanged = NULL;



	public function __construct($socket, LoopInterface $loop, LoggerInterface $logger)
	{
		parent::__construct($socket, $loop);

		$this->lastChanged = time();
		$this->logger = $logger;
	}



	public function handleData($stream)
	{
		if (feof($stream) || !is_resource($stream)) {
			$this->close();
			return;
		}

		$data = fread($stream, $this->bufferSize);
		if ('' === $data || FALSE === $data) {
			$this->close();
		} else {
			$this->onData($data);
		}
	}



	private function onData($data)
	{
		try {
			$this->lastChanged = time();

			if ($this->transport) {
				$this->emit('data', array($data, $this));
			} else {
				$this->establishConnection($data);
			}
		} catch (Exception $e) {
			$this->logger->error("Error while handling incoming data. Exception message is: " . $e->getMessage());
			$this->close();
		}
	}



	public function setTransport(WebSocketTransportInterface $con)
	{
		$this->transport = $con;
	}



	public function establishConnection($data)
	{
		$this->transport = WebSocketTransportFactory::fromSocketData($this, $data, $this->logger);
		$myself = $this;

		$this->transport->on("handshake", function (Handshake $request) use ($myself) {
			$myself->emit("handshake", array($request));
		});

		$this->transport->on("connect", function () use ($myself) {
			$myself->emit("connect", array($myself));
		});

		$this->transport->on("message", function ($message) use ($myself) {
			$myself->emit("message", array("message" => $message));
		});

		$this->transport->on("flashXmlRequest", function ($message) use ($myself) {
			$myself->emit("flashXmlRequest");
		});

		if ($this->transport instanceof WebSocketTransportFlash) {
			return;
		}

		$request = Request::fromString($data);
		$this->transport->respondTo($request);
	}



	public function getLastChanged()
	{
		return $this->lastChanged;
	}



	/**
	 * @return WebSocketTransportInterface
	 */
	public function getTransport()
	{
		return $this->transport;
	}



	public function setLogger(LoggerInterface $logger)
	{
		$this->logger = $logger;
	}
}
