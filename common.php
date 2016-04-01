<?php

/**

	Autonotify plugin by Andy Martin, Stanford University

**/

error_reporting(E_ALL);

class AutoNotify {
	
	// Message Parameters
	public $to, $from, $subject, $message, $post_det_url;

	public $config, $config_encoded;	// This is an array of parameters for the autoNotification object
	public $triggers;
	
	// DET Project Parameters
	public $project_id, $instrument, $record, $redcap_event_name, $event_id, $redcap_data_access_group, $instrument_complete;

	// Create an AutoNotify Object based from a DET call
	// Requires that the project context be set.  It loads the config from the an parameter in the query string
	public function loadFromDet() {
		// Get parameters from REDCap DET Post
		$this->project_id = voefr('project_id');
		$this->instrument = voefr('instrument');
		$this->record = voefr('record');
		$this->redcap_event_name = voefr('redcap_event_name');
		if (REDCap::isLongitudinal()) {
			$events = REDCap::getEventNames(true,false);
			$this->event_id = array_search($this->redcap_event_name, $events);
		}
		$this->redcap_data_access_group = voefr('redcap_data_access_group');
		$this->instrument_complete = voefr($instrument.'_complete');
		self::loadEncodedConfig(voefr('an'));
//error_log('Loaded from DET: '.print_r($this,true));
	}

	// Loads the config object from the encoded query string variable AN
	public function loadEncodedConfig($an) {
		$this->config_encoded = $an;
		$this->config = self::decrypt($this->config_encoded);
		if (isset($this->config['triggers'])) {
			$this->triggers = json_decode(htmlspecialchars_decode($this->config['triggers'], ENT_QUOTES), true);
		}
//		error_log(print_r($this,true));
//		error_log(print_r($this->config_encoded,true));
//		error_log(print_r(self::decrypt($this->config_encoded),true));
	}
	
	// Execute the loaded DET.  Returns false if any errors
	public function execute() {
		// Check for Pre-DET url
		self::checkPreDet();

		// Loop through each notification
		foreach ($this->triggers as $i => $trigger) {
			$logic = $trigger['logic'];
			$title = $trigger['title'];
			$enabled = $trigger['enabled'];
			
			if (!$enabled) {
				logIt("The current trigger ($title) is not set as enabled - skipping");
				continue;
			}
			
			// Append current event prefix to lonely fields if longitidunal
			if (REDCap::isLongitudinal() && $this->redcap_event_name) $logic = LogicTester::logicPrependEventName($logic, $this->redcap_event_name);
			
			if (!empty($logic) && !empty($this->record)) {
				if (LogicTester::evaluateLogicSingleRecord($logic, $this->record)) {
					// Condition is true, check to see if already notified
					if (!self::checkForPriorNotification($title)) {
						self::notify($title);
						logIt("{$this->record}: Notified ($title)");
					} else {
						// Already notified
						logIt("{$this->record}: Already notified ($title)");
					}
				} else {
					// Logic did not pass
					logIt("{$this->record}: Logic test failed ($title)");
				}
			} else {
				// object missing logic or record
				logIt("{$this->record}: Unable to execute: missing logic or record ($title)");
			}
		}	
		
		// Check for Post-DET url
		self::checkPostDet();
		
	}


	// Used to test the logic and return an appropriate image
	public function testLogic($logic) {
		
		logIt('Testing record '. $this->record . ' with ' . $logic);
		
		if (LogicTester::isValid($logic)) {
			
			// Append current event details
			if (REDCap::isLongitudinal() && $this->redcap_event_name) {
				$logic = LogicTester::logicPrependEventName($logic, $this->redcap_event_name);
				logIt('Logic updated with selected event: '. $logic);
			}	
			// Test logic
			//logIt('Logic:' . $logic, 'DEBUG');
			//logIt('This:' . print_r($this,true), 'DEBUG');
			
			if (LogicTester::evaluateLogicSingleRecord($logic, $this->record)) {
				$result = RCView::img(array('class'=>'imgfix', 'src'=>'accept.png'))." True";
			} else {
				$result = RCView::img(array('class'=>'imgfix', 'src'=>'cross.png'))." False";
			}
		} else {
			$result = RCView::img(array('class'=>'imgfix', 'src'=>'error.png'))." Invalid Syntax";
		}
		return $result;
	}

	// Check if there is a pre-trigger DET configured - if so, post to it.
	public function checkPreDet() {
		if ($this->config['pre_script_det_url']) {
			self::callDets($this->config['pre_script_det_url']);
		}
	}
	
	// Check if there is a post-trigger DET configured - if so, post to it.
	public function checkPostDet() {
		if ($this->config['post_script_det_url']) {
         logIt('DET URL: ' . $this->config['post_script_det_url'], "DEBUG");
			self::callDets($this->config['post_script_det_url']);
		}
	}

	// Takes a comma-separated list of urls and calls them as DETs
	private function callDets($urls) {
		$dets = explode(',',$urls);
		foreach ($dets as $det_url) {
			$det_url = trim($det_url);
			http_post($det_url, $_POST, 10);				
		}
	}

	// Notify and log
	public function notify($title) {
		global $redcap_version;
		$dark = "#800000";	//#1a74ba  1a74ba
		$light = "#FFE1E1";		//#ebf6f3
		$border = "#800000";	//FF0000";	//#a6d1ed	#3182b9
		// Run notification
		$url = APP_PATH_WEBROOT_FULL . "redcap_v{$redcap_version}/" . "DataEntry/index.php?pid={$this->project_id}&page={$this->instrument}&id={$this->record}&event_id={$this->event_id}";
		// Message (email html painfully copied from box.net notification email)
		$msg = RCView::table(array('cellpadding'=>'0', 'cellspacing'=>'0','border'=>'0','style'=>'border:1px solid #bbb; font:normal 12px Arial;color:#666'),
			RCView::tr(array(),
				RCView::td(array('style'=>'padding:13px'),
					RCView::table(array('style'=>'font:normal 15px Arial'),
						RCView::tr(array(),
							RCView::td(array('style'=>'font-size:18px;color:#000;border-bottom:1px solid #bbb'),
								RCView::span(array('style'=>'color:black'),
									RCVieW::a(array('style'=>'color:black'),
										'REDCap AutoNotification Alert'
									)
								).
								RCView::br()
							)
						).
						RCView::tr(array(),
							RCView::td(array('style'=>'padding:10px 0'),
								RCView::table(array('style'=>'font:normal 12px Arial;color:#666'),
									RCView::tr(array(),
										RCView::td(array('style'=>'text-align:right'),
											"Title"
										).
										RCView::td(array('style'=>'padding-left:10px;color:#000'),
											RCView::span(array('style'=>'color:black'),
												RCView::a(array('style'=>'color:black'),
													"<b>$title</b>"
												)
											)
										)
									).
									RCView::tr(array(),
										RCView::td(array('style'=>'text-align:right'),
											"Project"
										).
										RCView::td(array('style'=>'padding-left:10px;color:#000'),
											RCView::span(array('style'=>'color:black'),
												RCView::a(array('style'=>'color:black'),
													REDCap::getProjectTitle()
												)
											)
										)
									).
									($this->redcap_event_name ? (RCView::tr(array(),
										RCView::td(array('style'=>'text-align:right'),
											"Event"
										).
										RCView::td(array('style'=>'padding-left:10px;color:#000'),
											RCView::span(array('style'=>'color:black'),
												RCView::a(array('style'=>'color:black'),
													"$this->redcap_event_name"
												)
											)
										)
									)) : '').
									RCView::tr(array(),
										RCView::td(array('style'=>'text-align:right'),
											"Instrument"
										).
										RCView::td(array('style'=>'padding-left:10px;color:#000'),
											RCView::span(array('style'=>'color:black'),
												RCView::a(array('style'=>'color:black'),
													$this->instrument
												)
											)
										)
									).
									RCView::tr(array(),
										RCView::td(array('style'=>'text-align:right'),
											"Record"
										).
										RCView::td(array('style'=>'padding-left:10px;color:#000'),
											RCView::span(array('style'=>'color:black'),
												RCView::a(array('style'=>'color:black'),
													$this->record
												)
											)
										)
									).
									RCView::tr(array(),
										RCView::td(array('style'=>'text-align:right'),
											"Date/Time"
										).
										RCView::td(array('style'=>'padding-left:10px;color:#000'),
											RCView::span(array('style'=>'color:black'),
												RCView::a(array('style'=>'color:black'),
													date('Y-m-d H:i:s')
												)
											)
										)
									).
									RCView::tr(array(),
										RCView::td(array('style'=>'text-align:right'),
											"Message"
										).
										RCView::td(array('style'=>'padding-left:10px;color:#000'),
											RCView::span(array('style'=>'color:black'),
												RCView::a(array('style'=>'color:black'),
													$this->config['message']
												)
											)
										)
									)
								)
							)
						).
						RCView::tr(array(),
							RCView::td(array('style'=>"border:1px solid $border;background-color:$light;padding:20px"),
								RCView::table(array('style'=>'font:normal 12px Arial', 'cellpadding'=>'0','cellspacing'=>'0'),
									RCView::tr(array('style'=>'vertical-align:middle'),
										RCView::td(array(),
											RCView::table(array('cellpadding'=>'0','cellspacing'=>'0'),
												RCView::tr(array(),
													RCView::td(array('style'=>"border:1px solid #600000;background-color:$dark;padding:8px;font:bold 12px Arial"),
														RCView::a(array('class'=>'hide','style'=>'color:#fff;white-space:nowrap;text-decoration:none','href'=>$url),
															"View Record"
														)
													)
												)
											)
										).
										RCView::td(array('style'=>'padding-left:15px'),
											"To view this record, visit this link:".
											RCView::br().
											RCView::a(array('style'=>"color:$dark",'href'=>$url),
												$url
											)
										)
									)
								)
							)
						)
					)
				)
			)
		);
		$msg = "<HTML><head></head><body>".$msg."</body></html>";
		
		// Determine number of emails to send
		
		// Prepare message
		$email = new Message();
		$email->setTo($this->config['to']);
		$email->setFrom($this->config['from']);
		$email->setSubject($this->config['subject']);
		$email->setBody($msg);

		// Send Email
		if (!$email->send()) {
			error_log('Error sending mail: '.$email->getSendError().' with '.json_encode($email));
			exit;
		}
//		error_log ('Email sent');
		
		// Add Log Entry
		$data_values = "title,$title\nrecord,{$this->record}\nevent,{$this->redcap_event_name}";
		REDCap::logEvent('AutoNotify Alert',$data_values);
	}
	
	// Go through logs to see if there is a prior alert for this record/event/title
	public function checkForPriorNotification($title) {
		$sql = "SELECT l.data_values, l.ts 
			FROM redcap_log_event l WHERE 
		 		l.project_id = {$this->project_id}
			AND l.page = 'PLUGIN' 
			AND l.description = 'AutoNotify Alert';";
		$q = db_query($sql);
		while ($row = db_fetch_assoc($q)) {
			$pairs = parseEnum($row['data_values']);
			if ($pairs['title'] == $title &&
				$pairs['record'] == $this->record &&
				$pairs['event'] == $this->redcap_event_name)
			{
				$date = substr($row['ts'], 4, 2) . "/" . substr($row['ts'], 6, 2) . "/" . substr($row['ts'], 0, 4);
				$time = substr($row['ts'], 8, 2) . ":" . substr($row['ts'], 10, 2);

				// Already triggered
//				error_log("Trigger previously matched on $date $time ". json_encode($row));
				return true;
			}
		}
		return false;
	}
	
	// Takes an encoded string and returns the array representation of the object
	public function decrypt($code) {
		$template_enc = rawurldecode($code);
		$json = decrypt_me($template_enc);	//json string representation of parameters
		$params = json_decode($json, true);	//array representation
		return $params;
	}
	
	// Takes an array and returns the encoded string
	public function encode($params) {
		$json = json_encode($params);
		$encoded = encrypt($json);
		return rawurlencode($encoded);
	}
	
	// Renders the triggers portion of the page, or an empty trigger if new
	public function renderTriggers() {
		$html = "<div id='triggers_config'>";
		if (isset($this->triggers)) {
			foreach ($this->triggers as $i => $trigger) {
				$html .= self::renderTrigger($i, $trigger['title'], $trigger['logic'], $trigger['test_record'], $trigger['test_event'], $trigger['enabled']);
			}
		} else {
			$html .= self::renderTrigger(1);
		}
		$html .= "</div>";
		return $html;
	}
	
	// Render an individual trigger (also called by Ajax to add a new trigger to the page)
	public function renderTrigger($id, $title = '', $logic = '', $test_record = null, $test_event = null, $enabled = 1) {
		$html = RCView::div(array('class'=>'round chklist trigger','idx'=>"$id"),
			RCView::div(array('class'=>'chklisthdr', 'style'=>'color:rgb(128,0,0); margin-bottom:5px; padding-bottom:5px; border-bottom:1px solid #AAA;'), "Trigger $id: $title".
				RCView::a(array('href'=>'javascript:','onclick'=>"removeTrigger('$id')"), RCView::img(array('style'=>'float:right;padding-top:0px;', 'src'=>'cross.png')))
			).
			RCView::table(array('cellspacing'=>'5', 'class'=>'tbi'),
				self::renderRow('title-'.$id,'Title',$title, 'title').
				self::renderLogicRow($id,'Conditional Logic',$logic, 'logic').
				self::renderTestRow($id,'Test Logic', $test_record, $test_event, 'test').
				self::renderEnabledRow($id,'<nobr>Trigger Status</nobr>', $enabled)
				//	self::renderStatusRow();	// Enable or Disable the current trigger
			)
		);
		return $html;
	}
	
	// Adds a single row with an input
	public function renderRow($id, $label, $value, $help_id = null) {
		$help_id = ( $help_id ? $help_id : $id);
		$row = RCView::tr(array(),
			RCView::td(array('class'=>'td1'), self::insertHelp($help_id)).
			RCView::td(array('class'=>'td2'), "<label for='$id'><b>$label:</b></label>").
			RCView::td(array('class'=>'td3'),
		 		RCView::input(array('class'=>'tbi x-form-text x-form-field','id'=>$id,'name'=>$name,'value'=>$value))
			)
		);
		return $row;
	}

	// Renders the logic row with the text area
	public function renderLogicRow($id, $label, $value) {
		$row = RCView::tr(array(),
			RCView::td(array('class'=>'td1'), self::insertHelp('logic')).
			RCView::td(array('class'=>'td2'), "<label for='logic-$id'><b>$label:</b></label>").
			RCView::td(array('class'=>'td3'),
			 	RCView::textarea(array('class'=>'tbi x-form-text x-form-field','id'=>"logic-$id",'name'=>"logic-$id",'onblur'=>"testLogic('$id');"), $value).
				RCView::div(array('style'=>'text-align:right'),
					RCView::a(array('onclick'=>'growTextarea("logic-'.$id.'")', 'style'=>'font-weight:normal;text-decoration:none;color:#999;font-family:tahoma;font-size:10px;', 'href'=>'javascript:;'),'Expand')
				)
			)
		);
		return $row;
	}
	
	// Renders the enabled row
	public function renderEnabledRow($id, $label, $value) {
		//error_log('ID:'.$id.' and VALUE:'.$value);
		$enabledChecked = ($value == 1 ? 'checked' : '');
		$disabledChecked = ($value == 1 ? '' : 'checked');
		$row = RCView::tr(array(),
			RCView::td(array('class'=>'td1'), self::insertHelp('enabled')).
			RCView::td(array('class'=>'td2'), "<label for='logic-$id'><b>$label:</b></label>").
			RCView::td(array('class'=>'td3'),
				RCView::span(array(),
					RCView::radio(array('name'=>"enabled-$id",'value'=>'1',$enabledChecked=>$enabledChecked)). 
					'Enabled' . RCView::SP . RCView::SP .
					RCView::radio(array('name'=>"enabled-$id",'value'=>'0',$disabledChecked=>$disabledChecked)). 
					'Disabled'
				)
			)
		);
		return $row;
	}
	
	
	// Renders a test row with dropdowns for the various events/records in the project
	public function renderTestRow($id, $label, $selectedRecord, $selectedEvent) {
		// Make a dropdown that contains all record_ids.
		$data = REDCap::getData('array', NULL, REDCap::getRecordIdField());
//error_log("data: ".print_r($data,true));
		foreach ($data as $record_id => $arr) $record_id_options[$record_id] = $record_id;

		// Get all Events
		$events = REDCap::getEventNames(TRUE,FALSE);
		$row = RCView::tr(array(),
			RCView::td(array('class'=>'td1'), self::insertHelp('test')).
			RCView::td(array('class'=>'td2'), "<label for='test-$id'><b>$label:</b></label>").
			RCView::td(array('class'=>'td3'),
				RCView::span(array(), "Test logic using ".REDCap::getRecordIdField().":".
					RCView::select(array('id'=>"test_record-$id", 'name'=>"test_record-$id", 'class'=>"tbi x-form-text x-form-field", 'style'=>'height:20px;border:0px;', 'onchange'=>"testLogic('$id');"), $record_id_options, $selectedRecord)
				).
				RCView::span(array('style'=>'display:'. (REDCap::isLongitudinal() ? 'inline;':'none;')), " of event ".
					RCView::select(array('id'=>"test_event-$id", 'name'=>"test_event-$id", 'class'=>"tbi x-form-text x-form-field", 'style'=>'height:20px;border:0px;', 'onchange'=>"testLogic('$id');"), $events, $selectedEvent)
				).
				RCView::span(array(),
					RCView::button(array('class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only','onclick'=>'testLogic("'.$id.'");', 'style'=>'margin:0px 10px;'), 'Test').
					RCView::span(array('id'=>'result-'.$id))
				)
			)
		);
		return $row;	
	}
	
	public function insertHelp($e) {
		return "<span><a href='javascript:;' id='".$e."_info_trigger' info='".$e."_info' class='info' title='Click for help'><img class='imgfix' style='height: 16px; width: 16px;' src='".APP_PATH_IMAGES."help.png'></a></span>";
	}

	public function updateDetUrl($an) {
		global $data_entry_trigger_url;
		// Build the complete URL of the page that called us
		$url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";

		// At stanford, we use http inside of the load balancer
		if (strpos($url, 'stanford.edu') !== false) $url = str_replace('https','http',$url);

		// Trim the non-path portion off the end of the URL
		$data_entry_trigger_url = join('/',array_slice(mb_split('/', $url), 0, -1));

		// Add an variable
		$data_entry_trigger_url .= '/det.php?an=' . $an;
		$sql = "update redcap_projects set data_entry_trigger_url = '".prep($data_entry_trigger_url)."' where project_id = " . PROJECT_ID . " LIMIT 1;";
		$q = db_query($sql);
		//echo "$sql";
	}

	public function renderHelpDivs() {
		$help = RCView::div(array('id'=>'to_info','style'=>'display:none;'),
			RCView::p(array(),'The following are valid email formats:'.
				RCView::ul(array('style'=>'margin-left:15px;'),
					RCView::li(array(),'&raquo; user@example.com').
					RCView::li(array(),'&raquo; user@example.com, anotheruser@example.com')
				)
			)
		).RCView::div(array('id'=>'from_info','style'=>'display:none;'),
			RCView::p(array(),'Please note that some spam filters my classify this email as spam - you should test prior to going into production.'.
				RCView::ul(array('style'=>'margin-left:15px;'),
					RCView::li(array(),'A valid format is: user@example.com')
				)
			)
		).RCView::div(array('id'=>'subject_info','style'=>'display:none;'),
			RCView::p(array(),'To send a secure message, prefix the subject with <B>SECURE:</b>'.
				RCView::ul(array('style'=>'margin-left:15px;'),
					RCView::li(array(),'&raquo; Secure messages open normally for Stanford SOM users but require additional authentication for non-Stanford SOM email accounts.')
				)
			)
		).RCView::div(array('id'=>'message_info','style'=>'display:none;'),
			RCView::p(array(),'This message will be included in the alert.  Piping is not supported.')
		).
		RCView::div(array('id'=>'title_info','style'=>'display:none;'),
			RCView::p(array(),'An alert will only be fired once per record/event/title.  This means that if you rename a trigger it may re-fire next time you save a previously true record.  The title of the alert will also be included in the notification email.')
		).
		RCView::div(array('id'=>'logic_info','style'=>'display:none;'),
			RCView::p(array(),'This is an expression that will be evaluated to determine if the saved record should trigger an alert.  You should use the same format you use for branching logic.')
		).
		RCView::div(array('id'=>'test_info','style'=>'display:none;'),
			RCView::p(array(),'You can test your logical expression by selecting a record (and event) to evaluate the expression against.  This is useful if you have an existing record that would be a match for your condition.')
		).RCView::div(array('id'=>'post_script_det_url_info','style'=>'display:none;'),
			RCView::p(array(),'By inserting a comma-separated list of valid URLs into this field you can trigger additional DETs <b>AFTER</b> this one is complete.  This is useful for chaining DETs together.')
		).RCView::div(array('id'=>'pre_script_det_url_info','style'=>'display:none;'),
			RCView::p(array(),'By inserting a comma-separated list of valid URLs into this field you can trigger additional DETs to run <b>BEFORE</b> this notification trigger.  This might be useful for running an auto-scoring algorithm, for example.')
		);
		
		
		echo $help;
	}

} // End of Class



function renderTemporaryMessage($msg, $title='') {
	$id = uniqid();
	$html = RCView::div(array('id'=>$id,'class'=>'green','style'=>'margin-top:20px;padding:10px 10px 15px;'),
		RCView::div(array('style'=>'text-align:center;font-size:20px;font-weight:bold;padding-bottom:5px;'), $title).
		RCView::div(array(), $msg)
	);
	$js = "<script type='text/javascript'>
	$(function(){
		t".$id." = setTimeout(function(){
			$('#".$id."').hide('blind',1500);
		},10000);
		$('#".$id."').bind( 'click', function() { 
			$(this).hide('blind',1000);
			window.clearTimeout(t".$id.");
		});
	});
	</script>";
	echo $html . $js;
}


// Get variable or empty string from _REQUEST
function voefr($var) {
	$result = isset($_REQUEST[$var]) ? $_REQUEST[$var] : "";
	return $result;
}

function insertImage($i) {
	return "<img class='imgfix' style='height: 16px; width: 16px; vertical-align: middle;' src='".APP_PATH_IMAGES.$i.".png'>";
}

#display an error from scratch
function showError($msg) {
	$HtmlPage = new HtmlPage();
	$HtmlPage->PrintHeaderExt();
	echo "<div class='red'>$msg</div>";
}

function injectPluginTabs($pid, $plugin_path, $plugin_name) {
	$msg = '<script>
		jQuery("#sub-nav ul li:last-child").before(\'<li class="active"><a style="font-size:13px;color:#393733;padding:4px 9px 7px 10px;" href="'.$plugin_path.'"><img src="' . APP_PATH_IMAGES . 'email.png" class="imgfix" style="height:16px;width:16px;"> ' . $plugin_name . '</a></li>\');
		</script>';
	echo $msg;
}

function logIt($msg, $level = "INFO") {
	global $log_prefix;
	file_put_contents( $log_prefix . "-" . date( 'Y-m' ) . ".log",	date( 'Y-m-d H:i:s' ) . "\t" . $level . "\t" . $msg . "\n", FILE_APPEND );
}

// Function for decrypting (from version 643)
function decrypt_643($encrypted_data, $custom_salt=null) 
{
	if (!mcrypt_loaded()) return false;
	// $salt from db connection file
	global $salt;
	// If $custom_salt is not provided, then use the installation-specific $salt value
	$this_salt = ($custom_salt === null) ? $salt : $custom_salt;
	// If salt is longer than 32 characters, then truncate it to prevent issues
	if (strlen($this_salt) > 32) $this_salt = substr($this_salt, 0, 32);
	// Define an encryption/decryption variable beforehand
	defined("MCRYPT_IV") or define("MCRYPT_IV", mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND));
	// Decrypt and return
	return rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $this_salt, base64_decode($encrypted_data), MCRYPT_MODE_ECB, MCRYPT_IV),"\0");
}

function decrypt_me($encrypted_data) {
	// Try decrypting using the current format:
	$t1 = decrypt($encrypted_data);
	$t1_json = json_decode($t1,true);
	if (json_last_error() == JSON_ERROR_NONE) return $t1;
	$t2 = decrypt_643($encrypted_data);
	$t2_json = json_decode($t2,true);
	if (json_last_error() == JSON_ERROR_NONE) return $t2;
	print "ERROR DECODING";
	return false;
}


?>
