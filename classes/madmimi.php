<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Kohana interface to the madmimi (http://www.madmimi.com) api. 
 *
 * Note: You will need to have curl enabled or she won't work
 *
 * @package    Madmimi
 * @author     Unmagnify Team
 * @copyright (c) 2010 Unmagnify team
 */
class Madmimi {

	protected $_config;
	
	public function __construct($config = NULL)
	{
		// Set the config
		if (empty($config))
		{
			$this->_config = Kohana::config('madmimi.default');
		}
		elseif (is_array($config))
		{
			// Setup the config
			$config += Kohana::config('madmimi.default');
			$this->_config = $config;
		}
		elseif (is_string($config))
		{
			if ($config = Kohana::config('madmimi.'.$config) === NULL)
			{
				throw new Madmimi_Exception('Madmimi, invalid configuration group name : :config', array(':config' => $config));
			}

			$this->_config = $config + Kohana::config('madmimi.default');
		}
	}

	/**
	 * Send the request to madmimi for processing, catch anything returned
	 * 
	 * @param	string	Name of the action to call
	 * @param	array 	The parameters to send (these will be merged with $_config)
	 * @param	boolean	Send by POST (FALSE is obviously GET)
	 * @param	boolean	Send via SSL (securely send mail data to the api)
	 * @return	mixed	The response from the api
	 */
	private function send_request($action, array $params, $post = TRUE, $secure = TRUE)
	{
		// Merge the config with the parameters
		$params = array_merge($this->_config, $params);
		
		// The url is based on whether we are being $secure or not. Madmimi 
		// defines what needs to be secure, but its basically all the mail 
		// functions. 
		// Add the action on the end.
		if ($secure)
		{
			$url = 'https://api.madmimi.com'.$action;
		}
		else
		{
			$url = 'http://api.madmimi.com'.$action;
		}
		
		// Setup curl to deliver the message
		$ch = curl_init();
		
		// Some items are posted, some are got. Set the curl options accordingly
		if ($post)
		{
			// Using POST
			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
			
			if ($secure)
			{
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
			}
		}
		else
		{
			// Using GET
			$url .= '?'.http_build_query($params);
		}
		
		// Shared curl options
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		
		// Execute and store the results
		if (($result = curl_exec($ch)) === FALSE)
		{
			throw new Madmimi_Exception('There was a error requesting the madmimi api: :curl_error', array(':curl_error'=>curl_error($ch)));
		}
		
		// Close the connection and return the result
		curl_close($ch);
		return $result;
	}


	/**
	 * Send a mail message 
	 * 
	 * Example :
	 * 		$madmimi = new Madmimi;
	 * 		$madmimi->mail(array(
	 * 			'promotion_name'=>'signup',
	 * 			'recipients'=>'Andrew Edwards <andrew@unmagnify.com>',
	 *			'subject'=>'Welcome',
	 * 			'body'=>array('username'=>'Andrew'),
	 * 			'from'=>'Unmagnify team <info@unmagnify.com>'
	 * 		));
	 *
	 */
	public function mail(array $params)
	{
		// Check to see if we are sending html or text
		if ( ! empty($params['raw_html']))
		{
			// We are sending a html email, check for tracking beacon etc.
			if (strpos($params['raw_html'], '[[tracking_beacon]]') === FALSE
				AND strpos($params['raw_html'], '[[peek_image]]') === FALSE)
			{
				throw new Madmimi_Exception('Please include either the [[tracking_beacon]] or the [[peek_image]] macro in your HTML.');
			}
		}
		elseif ( ! empty($params['raw_plain_text']))
		{
			// We are sending a plain text email, check for the unsubscribe macro
			if (strpos($params['raw_plain_text'], '[[unsubscribe]]') === FALSE)
			{
				throw new Madmimi_Exception('Please include the [[unsubscribe]] macro in your text.');
			}
		}
		
		// Check for body (needs to be yaml)
		if (array_key_exists('body',$params) AND is_array($params['body']))
		{
			$params['body'] = $this->yamlise($params['body']);
		}
		
		// Check for a list name 
		if ( ! empty($params['list_name']))
		{
			$action = '/mailer/to_list';
		}
		else
		{
			$action = '/mailer';
		}
		
		return $this->send_request($action, $params, TRUE, TRUE);
	}
	
	/**
	 * Create a new list
	 * 
	 * @param 	string 	The new lists name
	 * @return 	mixed	The response from madmimi
	 */
	public function list_add($list_name)
	{
		return $this->send_request('/audience_lists', array('name'=>$list_name), TRUE, FALSE);	
	}
	
	/**
	 * Add a member to a list
	 * 
	 * @param 	string 	The new lists name
	 * @return 	mixed	The response from madmimi
	 */
	public function list_add_member($list_name, $email)
	{
		return $this->send_request('/audience_lists/'.$list_name.'/add?email='.$email, array(), FALSE, FALSE);	
	}
	
	/**
	 * Add a member
	 *
	 * @param	array	The members data
	 * @return	mixed	The response from madmimi
	 */
	public function member_add(array $member_data)
	{
		return $this->send_request('/audience_members', array('csv_file'=>$this->csv($member_data)), TRUE, FALSE);	
	}
	
	/**
	 * Turn an array into yaml data
	 * 
	 * @param 	array	Values to be turned into yaml
	 * @return	string	Yaml formatted values
	 */
	private function yamlise(array $input)
	{
		// Find the sfYAML library
		$path = Kohana::find_file('vendor', 'sfYaml/sfYaml');

		// Load the sfYAML library
		Kohana::load($path);
		
		return sfYaml::dump($input);
	}
	
	/**
	 * Turn an array into csv data
	 * 
	 * @param	array	Values to be turned into csv (arrays of arrays)
	 * @return string Csv formatted values
	 */
	private function csv(array $input)
	{
		$csv_string = '';
		
		// Add headers
		$headers = array_keys($input[0]);
		array_unshift($input, $headers);
		
		// Create the csv string
		foreach ($input as $row)
		{
			$data = array();
			foreach ($row as $key=>$value)
			{
				$data[$key] = utf8_decode(str_replace('"', '""', $value));
			}
			$csv_string .= implode(',', $data) . "\r\n";
		}
		
		return $csv_string;
	}
}
