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
use Devristo\Phpws\Messaging\WebSocketMessageInterface;
use Evenement\EventEmitter;
use React\Stream\WritableStreamInterface;
use Zend\Http\Request;
use Zend\Http\Response;
use Psr\Log\LoggerInterface;



abstract class WebSocketTransport extends EventEmitter implements WebSocketTransportInterface
{

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * @var Request
	 */
	protected $request;

	/**
	 * @var Response
	 */
	protected $response;

	/**
	 * @var WebSocketConnection
	 */
	protected $socket = NULL;

	protected $cookies = array();

	public $parameters = NULL;

	protected $role = WebsocketTransportRole::CLIENT;

	protected $data = array();



	public function __construct(WritableStreamInterface $socket)
	{
		$this->socket = $socket;
		$this->_id = uniqid("connection-");

		$that = $this;

		$buffer = '';

		$socket->on("data", function ($data) use ($that, &$buffer) {
			$buffer .= $data;
			$that->handleData($buffer);
		});

		$socket->on("close", function ($data) use ($that) {
			$that->emit("close", func_get_args());
		});
	}



	public function getIp()
	{
		return $this->socket->getRemoteAddress();
	}



	public function getId()
	{
		return $this->_id;
	}



	protected function setRequest(Request $request)
	{
		$this->request = $request;
	}



	protected function setResponse(Response $response)
	{
		$this->response = $response;
	}



	public function getHandshakeRequest()
	{
		return $this->request;
	}



	public function getHandshakeResponse()
	{
		return $this->response;
	}



	public function getSocket()
	{
		return $this->socket;
	}



	public function setLogger(LoggerInterface $logger)
	{
		$this->logger = $logger;
	}



	public function sendFrame(WebSocketFrameInterface $frame)
	{
		if ($this->socket->write($frame->encode()) === FALSE) {
			return FALSE;
		}

		return TRUE;
	}



	public function sendMessage(WebSocketMessageInterface $msg)
	{
		foreach ($msg->getFrames() as $frame) {
			if ($this->sendFrame($frame) === FALSE) {
				return FALSE;
			}
		}

		return TRUE;
	}



	public function setData($key, $value)
	{
		$this->data[$key] = $value;
	}



	public function getData($key)
	{
		return $this->data[$key];
	}
}
