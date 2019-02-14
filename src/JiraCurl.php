<?php

namespace srag\JiraCurl;

use CURLFile;
use ilCurlConnection;
use ilCurlConnectionException;
use ilLogLevel;
use srag\DIC\DICTrait;
use Throwable;

/**
 * Class JiraCurl
 *
 * @package srag\JiraCurl
 *
 * @author  studer + raimann ag - Team Custom 1 <support-custom1@studer-raimann.ch>
 */
class JiraCurl {

	use DICTrait;
	/**
	 * @var string
	 */
	const AUTHORIZATION_USERNAMEPASSWORD = "usernamepassword";
	/**
	 * @var string
	 */
	const AUTHORIZATION_OAUTH = "oauth";
	/**
	 * @var string
	 */
	protected $jira_domain = "";
	/**
	 * @var string
	 */
	protected $jira_authorization = "";
	/**
	 * @var string
	 */
	protected $jira_username = "";
	/**
	 * @var string
	 */
	protected $jira_password = "";
	/**
	 * @var string
	 */
	protected $jira_consumer_key = "";
	/**
	 * @var string
	 */
	protected $jira_private_key = "";
	/**
	 * @var string
	 */
	protected $jira_access_token = "";


	/**
	 * JiraCurl constructor
	 */
	public function __construct() {
	}


	/**
	 * Init a Jira Curl connection
	 *
	 * @param string $url
	 * @param array  $headers
	 *
	 * @return ilCurlConnection
	 * @throws ilCurlConnectionException
	 */
	protected function initCurlConnection(string $url, array $headers): ilCurlConnection {
		$curlConnection = new ilCurlConnection();

		$curlConnection->init();

		$curlConnection->setOpt(CURLOPT_RETURNTRANSFER, true);
		$curlConnection->setOpt(CURLOPT_VERBOSE, true);
		$curlConnection->setOpt(CURLOPT_SSL_VERIFYPEER, false);
		$curlConnection->setOpt(CURLOPT_SSL_VERIFYHOST, false);
		$curlConnection->setOpt(CURLOPT_URL, $url);

		switch ($this->jira_authorization) {
			case self::AUTHORIZATION_USERNAMEPASSWORD:
				$curlConnection->setOpt(CURLOPT_USERPWD, $this->jira_username . ":" . $this->jira_password);
				break;

			case self::AUTHORIZATION_OAUTH:
				$nonce = sha1(uniqid("", true) . $url);
				$signature_method = "RSA-SHA1";
				$timestamp = time();

				$o_auth = [
					"oauth_consumer_key" => $this->jira_consumer_key,
					"oauth_nonce" => $nonce,
					"oauth_signature_method" => $signature_method,
					"oauth_timestamp" => $timestamp,
					"oauth_token" => $this->jira_access_token,
					"oauth_version" => "1.0"
				];

				$string_to_sign = "POST&" . rawurlencode($url) . "&" . rawurlencode(implode("&", array_map(function ($key, $value) {
						return (rawurlencode($key) . "=" . rawurlencode($value));
					}, array_keys($o_auth), $o_auth)));

				$certificate = openssl_pkey_get_private($this->jira_private_key);
				$private_key_id = openssl_get_privatekey($certificate);

				$signature = NULL;
				openssl_sign($string_to_sign, $signature, $private_key_id);
				$signature = base64_encode($signature);

				try {
					openssl_free_key($private_key_id);
					openssl_free_key($certificate);
				} catch (Throwable $ex) {

				}

				$o_auth["oauth_signature"] = $signature;

				$headers["Authorization"] = "OAuth " . implode(", ", array_map(function ($key, $value) {
						return (urlencode($key) . '="' . urlencode($value) . '"');
					}, array_keys($o_auth), $o_auth));
				break;

			default:
				break;
		}

		$headers = array_map(function ($key, $value) {
			return ($key . ": " . $value);
		}, array_keys($headers), $headers);

		$curlConnection->setOpt(CURLOPT_HTTPHEADER, $headers);

		return $curlConnection;
	}


	/**
	 * Jira request
	 *
	 * @param string $rest_url
	 * @param array  $headers
	 * @param mixed  $post_data
	 *
	 * @return array|null
	 */
	protected function doRequest(string $rest_url, array $headers, $post_data = NULL)/*: ?array*/ {
		$url = $this->jira_domain . $rest_url;

		$curlConnection = NULL;

		try {
			$curlConnection = $this->initCurlConnection($url, $headers);

			if ($post_data !== NULL) {
				$curlConnection->setOpt(CURLOPT_POST, true);
				$curlConnection->setOpt(CURLOPT_POSTFIELDS, $post_data);
			}

			$result = $curlConnection->exec();

			$result_json = json_decode($result, true);
			if (!is_array($result_json)) {
				// Jira
				self::dic()->logger()->root()->log("Jira results: " . $result, ilLogLevel::ERROR);

				return NULL;
			}

			return $result_json;
		} catch (Throwable $ex) {
			self::dic()->logger()->root()->log("Jira exception: " . $ex->getMessage(), ilLogLevel::ERROR);

			// Curl-Error!
			return NULL;
		} finally {
			// Close Curl connection
			if ($curlConnection !== NULL) {
				$curlConnection->close();
				$curlConnection = NULL;
			}
		}
	}


	/**
	 * Create Jira issue ticket
	 *
	 * @param string $jira_project_key
	 * @param string $jira_issue_type
	 * @param string $summary
	 * @param string $description
	 *
	 * @return string|null Issue-Key
	 */
	public function createJiraIssueTicket(string $jira_project_key, string $jira_issue_type, string $summary, string $description)/*: ?string*/ {
		$headers = [
			"Accept" => "application/json",
			"Content-Type" => "application/json"
		];

		$data = [
			"fields" => [
				"project" => [
					"key" => $jira_project_key,
				],

				"summary" => $summary,

				"description" => $description,

				"issuetype" => [
					"name" => $jira_issue_type,
					"subtask" => false
				]
			]
		];

		$result = $this->doRequest("/rest/api/2/issue", $headers, json_encode($data));

		if ($result === NULL || !isset($result["key"])) {
			return NULL;
		}

		$issue_key = $result["key"];

		return $issue_key;
	}


	/**
	 * Add attachement to issue ticket
	 *
	 * @param string $issue_key
	 * @param string $attachement_name
	 * @param string $attachement_mime
	 * @param string $attachement_path
	 *
	 * @return bool
	 */
	public function addAttachmentToIssue(string $issue_key, string $attachement_name, string $attachement_mime, string $attachement_path): bool {
		$headers = [
			"Accept" => "application/json",
			"X-Atlassian-Token" => "nocheck"
		];

		$data = [
			"file" => new CURLFile($attachement_path, $attachement_mime, $attachement_name)
		];

		$result = $this->doRequest("/rest/api/2/issue/" . $issue_key . "/attachments", $headers, $data);

		return ($result !== NULL);
	}


	/**
	 * @return string
	 */
	public function getJiraDomain(): string {
		return $this->jira_domain;
	}


	/**
	 * @param string $jira_domain
	 */
	public function setJiraDomain(string $jira_domain)/*: void*/ {
		$this->jira_domain = $jira_domain;
	}


	/**
	 * @return string
	 */
	public function getJiraAuthorization(): string {
		return $this->jira_authorization;
	}


	/**
	 * @param string $jira_authorization
	 */
	public function setJiraAuthorization(string $jira_authorization)/*: void*/ {
		$this->jira_authorization = $jira_authorization;
	}


	/**
	 * @return string
	 */
	public function getJiraUsername(): string {
		return $this->jira_username;
	}


	/**
	 * @param string $jira_username
	 */
	public function setJiraUsername(string $jira_username)/*: void*/ {
		$this->jira_username = $jira_username;
	}


	/**
	 * @return string
	 */
	public function getJiraPassword(): string {
		return $this->jira_password;
	}


	/**
	 * @param string $jira_password
	 */
	public function setJiraPassword(string $jira_password)/*: void*/ {
		$this->jira_password = $jira_password;
	}


	/**
	 * @return string
	 */
	public function getJiraConsumerKey(): string {
		return $this->jira_consumer_key;
	}


	/**
	 * @param string $jira_consumer_key
	 */
	public function setJiraConsumerKey(string $jira_consumer_key)/*: void*/ {
		$this->jira_consumer_key = $jira_consumer_key;
	}


	/**
	 * @return string
	 */
	public function getJiraPrivateKey(): string {
		return $this->jira_private_key;
	}


	/**
	 * @param string $jira_private_key
	 */
	public function setJiraPrivateKey(string $jira_private_key)/*: void*/ {
		$this->jira_private_key = $jira_private_key;
	}


	/**
	 * @return string
	 */
	public function getJiraAccessToken(): string {
		return $this->jira_access_token;
	}


	/**
	 * @param string $jira_access_token
	 */
	public function setJiraAccessToken(string $jira_access_token)/*: void*/ {
		$this->jira_access_token = $jira_access_token;
	}
}
