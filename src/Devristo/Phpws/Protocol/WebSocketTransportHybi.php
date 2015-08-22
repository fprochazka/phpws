<?php
/**
 * Created by JetBrains PhpStorm.
 * User: chris
 * Date: 10/6/13
 * Time: 5:44 PM
 * To change this template use File | Settings | File Templates.
 */
namespace Devristo\Phpws\Protocol;

use Devristo\Phpws\Framing\WebSocketFrameInterface;
use Devristo\Phpws\Framing\WebSocketFrame;
use Devristo\Phpws\Framing\WebSocketOpcode;
use Devristo\Phpws\Messaging\WebSocketMessage;
use Exception;
use Zend\Http\Headers;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Uri\Uri;



class WebSocketTransportHybi extends WebSocketTransport
{

	/**
	 * @var WebSocketMessage
	 */
	private $openMessage = NULL;

	/**
	 * @var WebSocketFrame
	 */
	private $lastFrame = NULL;

	protected $connected = FALSE;



	public function respondTo(Request $request)
	{
		$this->request = $request;
		$this->role = WebsocketTransportRole::SERVER;
		$this->sendHandshakeResponse();
	}



	private function sendHandshakeResponse()
	{
		try {
			$challengeHeader = $this->getHandshakeRequest()->getHeader('Sec-Websocket-Key', NULL);

			if (!$challengeHeader) {
				throw new Exception("No Sec-WebSocket-Key received!");
			}

			// Check for newer handshake
			$challenge = $challengeHeader->getFieldValue();

			// Build response
			$response = new Response();
			$response->setStatusCode(101);
			$response->setReasonPhrase("WebSocket Protocol Handshake");

			$headers = new Headers();
			$response->setHeaders($headers);

			$headers->addHeaderLine("Upgrade", "WebSocket");
			$headers->addHeaderLine("Connection", "Upgrade");
			$headers->addHeaderLine("Sec-WebSocket-Accept", self::calcHybiResponse($challenge));

			$this->setResponse($response);

			$handshakeRequest = new Handshake($this->getHandshakeRequest(), $this->getHandshakeResponse());
			$this->emit("handshake", array($handshakeRequest));

			if ($handshakeRequest->isAborted()) {
				$this->close();

			} else {
				$this->socket->write($response->toString());
				$this->logger->debug("Got an HYBI style request, sent HYBY handshake response");

				$this->connected = TRUE;
				$this->emit("connect");
			}

		} catch (Exception $e) {
			$this->logger->error("Connection error, message: " . $e->getMessage());
			$this->close();
		}
	}



	private static function calcHybiResponse($challenge)
	{
		return base64_encode(sha1($challenge . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', TRUE));
	}



	private static function containsCompleteHeader($data)
	{
		return strstr($data, "\r\n\r\n");
	}



	public function handleData(&$data)
	{
		if (!$this->connected) {
			if (!$this->containsCompleteHeader($data)) {
				return array();
			}
			$data = $this->readHandshakeResponse($data);
		}

		$frames = array();
		while ($frame = WebSocketFrame::decode($data)) {
			if (WebSocketOpcode::isControlFrame($frame->getType())) {
				$this->processControlFrame($frame);
			} else {
				$this->processMessageFrame($frame);
			}

			$frames[] = $frame;
		}

		return $frames;
	}



	public function sendFrame(WebSocketFrameInterface $frame)
	{
		/**
		 * @var $hybiFrame WebSocketFrame
		 */
		$hybiFrame = $frame;

		// Mask IFF client!
		$hybiFrame->setMasked($this->role == WebsocketTransportRole::CLIENT);

		parent::sendFrame($hybiFrame);
	}



	/**
	 * Process a Message Frame
	 * Appends or creates a new message and attaches it to the user sending it.
	 * When the last frame of a message is received, the message is sent for processing to the
	 * abstract WebSocket::onMessage() method.
	 *
	 * @param WebSocketFrame $frame
	 */
	protected function processMessageFrame(WebSocketFrame $frame)
	{
		if ($this->openMessage && $this->openMessage->isFinalised() == FALSE) {
			$this->openMessage->takeFrame($frame);
		} else {
			$this->openMessage = WebSocketMessage::fromFrame($frame);
		}

		if ($this->openMessage && $this->openMessage->isFinalised()) {
			$this->emit("message", array('message' => $this->openMessage));
			$this->openMessage = NULL;
		}
	}



	/**
	 * Handle incoming control frames
	 * Sends Pong on Ping and closes the connection after a Close request.
	 *
	 * @param WebSocketFrame $frame
	 */
	protected function processControlFrame(WebSocketFrame $frame)
	{
		switch ($frame->getType()) {
			case WebSocketOpcode::CloseFrame :
				$this->logger->notice("Got CLOSE frame");

				$frame = WebSocketFrame::create(WebSocketOpcode::CloseFrame);
				$this->sendFrame($frame);

				$this->socket->close();
				break;
			case WebSocketOpcode::PingFrame :
				$frame = WebSocketFrame::create(WebSocketOpcode::PongFrame);
				$this->sendFrame($frame);
				break;
		}
	}



	public function sendString($msg)
	{
		try {
			$m = WebSocketMessage::create($msg);

			return $this->sendMessage($m);
		} catch (Exception $e) {
			$this->close();
		}

		return FALSE;
	}



	public function close()
	{
		$f = WebSocketFrame::create(WebSocketOpcode::CloseFrame);
		$this->sendFrame($f);

		$this->socket->close();
	}



	private static function randHybiKey()
	{
		return base64_encode(
			chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255))
			. chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255))
			. chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255))
			. chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255))
		);
	}



	public function initiateHandshake(Uri $uri)
	{
		$challenge = self::randHybiKey();

		$request = new Request();

		$requestUri = $uri->getPath();

		if ($uri->getQuery()) {
			$requestUri .= "?" . $uri->getQuery();
		}

		$request->setUri($requestUri);

		$request->getHeaders()
			->addHeaderLine("Connection", "Upgrade")
			->addHeaderLine("Host", $uri->getHost())
			->addHeaderLine("Sec-WebSocket-Key", $challenge)
			->addHeaderLine("Sec-WebSocket-Version", 13)
			->addHeaderLine("Upgrade", "websocket");

		$this->setRequest($request);

		$this->emit("request", array($request));

		$this->socket->write($request->toString());

		return $request;
	}



	private function readHandshakeResponse($data)
	{
		$response = Response::fromString($data);
		$this->setResponse($response);

		$handshake = new Handshake($this->request, $response);

		$this->emit("handshake", array($handshake));

		if ($handshake->isAborted()) {
			$this->close();
			return '';
		}

		$this->connected = TRUE;
		$this->emit("connect");

		return $response->getContent();
	}

}
