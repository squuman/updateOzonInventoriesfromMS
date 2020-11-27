<?php

/**
 * @autor Tikhonov Alexander <123546.90@mail.ru>
 */
class MoySklad
{
	protected $auth;
	protected $base = 'https://online.moysklad.ru/api/remap/1.1/';
	protected $requestTime = 60;

	public function __construct($login, $password)
	{
		$this->auth = base64_encode($login.':'.$password);
	}

	public function request($url, $data = array())
	{
		if (strpos($url, 'http') !== 0) {
			$url = $this->base . rtrim($url, '/');
		}

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Authorization: Basic '.$this->auth,
		));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->requestTime);

		if ($data) {
			$data($ch, CURLOPT_POSTFIELDS, $post);
			curl_setopt($ch, CURLOPT_PORT, true);
		}

		$response = curl_exec($ch);
		curl_close($ch);
		$data = json_decode($response, true);
		return $data;
	}
}