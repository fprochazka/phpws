<?php

namespace Devristo\Phpws\Client;

use Devristo\Phpws\Exceptions\WebSocketInvalidUrlScheme;
use Devristo\Phpws\Framing\WebSocketFrameInterface;
use Devristo\Phpws\Framing\WebSocketFrame;
use Devristo\Phpws\Framing\WebSocketOpcode;
use Devristo\Phpws\Messaging\WebSocketMessageInterface;
use Devristo\Phpws\Protocol\Handshake;
use Devristo\Phpws\Protocol\WebSocketTransport;
use Devristo\Phpws\Protocol\WebSocketTransportHybi;
use Devristo\Phpws\Protocol\WebSocketConnection;
use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\SocketClient\SecureConnector;
use React\Stream\DuplexStreamInterface;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Uri\Uri;



class WebSocket extends EventEmitter
{

	const STATE_HANDSHAKE_SENT = 0;
	const STATE_CONNECTED = 1;
	const STATE_CLOSING = 2;
	const STATE_CLOSED = 3;

	private $state = self::STATE_CLOSED;

	/**
	 * @var WebSocketConnection
	 */
	private $stream;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var LoopInterface
	 */
	private $loop;

	/**
	 * @var WebSocketTransport
	 */
	private $transport = NULL;

	private $socket;

	private $request;

	private $response;

	private $headers;

	private $isClosing = FALSE;

	private $streamOptions = NULL;



	public function __construct(LoopInterface $loop, LoggerInterface $logger, array $streamOptions = NULL)
	{
		$this->loop = $loop;
		$this->logger = $logger;
		$this->streamOptions = $streamOptions;

		$dnsResolverFactory = new \React\Dns\Resolver\Factory();
		$this->dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);
	}



	public function open($url, $timeOut = NULL)
	{
		$uri = new Uri($url);
		if (!in_array($uri->getScheme(), ['ws', 'wss'], TRUE)) {
			throw new WebSocketInvalidUrlScheme();
		}

		$isSecured = 'wss' === $uri->getScheme();
		$defaultPort = $isSecured ? 443 : 80;

		$connector = new Connector($this->loop, $this->dns, $this->streamOptions);

		if ($isSecured) {
			$connector = new SecureConnector($connector, $this->loop);
		}

		$deferred = new Deferred();

		$connector->create($uri->getHost(), $uri->getPort() ?: $defaultPort)
			->then(function (DuplexStreamInterface $stream) use ($uri, $deferred, $timeOut) {
				if ($timeOut) {
					$timeOutTimer = $this->loop->addTimer($timeOut, function () use ($deferred, $stream) {
						$stream->close();
						$this->logger->notice("Timeout occured, closing connection");
						$this->emit("error");
						$deferred->reject("Timeout occured");
					});

				} else {
					$timeOutTimer = NULL;
				}

				$transport = new WebSocketTransportHybi($stream);
				$transport->setLogger($this->logger);

				$this->transport = $transport;
				$this->stream = $stream;

				$stream->on("close", function () {
					$this->isClosing = FALSE;
					$this->state = WebSocket::STATE_CLOSED;
				});

				// Give the chance to change request
				$transport->on("request", function (Request $handshake) {
					$this->emit("request", func_get_args());
				});

				$transport->on("handshake", function (Handshake $handshake) {
					$this->request = $handshake->getRequest();
					$this->response = $handshake->getRequest();

					$this->emit("handshake", array($handshake));
				});

				$transport->on("connect", function () use (&$state, $transport, $timeOutTimer, $deferred) {
					if ($timeOutTimer) {
						$timeOutTimer->cancel();
					}

					$deferred->resolve($transport);
					$this->state = WebSocket::STATE_CONNECTED;
					$this->emit("connect");
				});

				$transport->on('message', function ($message) use ($transport) {
					$this->emit("message", array("message" => $message));
				});

				$transport->initiateHandshake($uri);
				$this->state = WebSocket::STATE_HANDSHAKE_SENT;

			}, function ($reason) use ($deferred) {
				$deferred->reject($reason);
				$this->logger->error($reason);
			});

		return $deferred->promise();
	}



	public function send($string)
	{
		$this->transport->sendString($string);
	}



	public function sendMessage(WebSocketMessageInterface $msg)
	{
		$this->transport->sendMessage($msg);
	}



	public function sendFrame(WebSocketFrameInterface $frame)
	{
		$this->transport->sendFrame($frame);
	}



	public function close()
	{
		if ($this->isClosing) {
			return;
		}

		$this->isClosing = TRUE;
		$this->sendFrame(WebSocketFrame::create(WebSocketOpcode::CloseFrame));

		$this->state = self::STATE_CLOSING;
		$stream = $this->stream;

		$closeTimer = $this->loop->addTimer(5, function () use ($stream) {
			$stream->close();
		});

		$loop = $this->loop;
		$stream->once("close", function () use ($closeTimer, $loop) {
			if ($closeTimer) {
				$loop->cancelTimer($closeTimer);
			}
		});
	}
}
