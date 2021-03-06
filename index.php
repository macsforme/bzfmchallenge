<?php

///////////////////////////////////////////////////////////////////////////////
///////////////////////////////// INFORMATION /////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

/////////////////////////////////// Author ////////////////////////////////////

// By Joshua Bodine
// https://github.com/macsforme/bzfmchallenge

// This software is released under the MIT license. Please see the accompanying file LICENSE.txt for details.

// Please see the accompanying file README.txt for setup information.

//////////////////////////////////// TODO /////////////////////////////////////

// no items

////////////////////////// Future Improvement Ideas ///////////////////////////

// make single player teams work
// action logging and viewing
// use mod_rewrite for/pretty/action/URLs

////////////////////////////// Development Notes //////////////////////////////

// single quotes should generally be used, except double quotes may be used for:
//	any strings with escape characters (some functions take these as arguments)
//	in MySQL queries where a string is being passed (e.g., 'columnName="value"')
//	any content intended for output (direct output, or variables intended for later output, like $error)
//
// for state tracking variables, the following relationships are inferred (avoid redundant checks, and use only the highest level check necessary)
//	$isAdmin infers array_key_exists('bzid', $_SESSION)
//	array_key_exists('bzid', $_SESSION) infers $databaseUp
//
//	$currentEvent infers $configUp
//	$configUp infers $databaseUp

//////////////////////////// Pre-Release Checklist ////////////////////////////

// check apache errors, search for dummy_, search for 9972, delete extraneous comments
// check tabbed format in HTML output
// check for prefix with every FROM/INTO query (including table.column specifications)
// validate HTML 4 strict
	// no config or missing/bad database
	// config prompt
	// home/no events
	// home/event started
	// home/event closed
	// home/all results in
	// home/all results in (as admin)
	// info
	// registration no event
	// registration with invitations pending
	// registration on team with opening and others to invite
	// abandon team
	// admin/event open/1 player banned
	// create event
	// edit event
	// delete event
	// enter result

///////////////////////////////////////////////////////////////////////////////
////////////////////////////// UTILITY FUNCTIONS //////////////////////////////
///////////////////////////////////////////////////////////////////////////////

function getDependentMatchNumber($match) {
	if($match == 1)
		return 0;
	$round = ceil(log($match + 1, 2));
	if(pow(2, $round) - $match > (pow(2, $round) - pow(2, $round - 1)) / 2)
		return(pow(2, $round - 1) - 1 - ($match - pow(2, $round - 1)));
	else
		return(pow(2, $round - 1) - 1 - (pow(2, $round) - 1 - $match));
}

function getRatings($bzids) {
	$ratings = Array();
	$matches = array();
	foreach(json_decode(file_get_contents('http://leaguesunited.org/api/players?bzids='.implode(',', $bzids)), TRUE)['players'] as $player)
		if(is_numeric($player['bzid']) && is_numeric($player['elo']) && in_array($player['bzid'], $bzids))
			$ratings[$player['bzid']] = $player['elo'];
	return $ratings;
}

///////////////////////////////////////////////////////////////////////////////
/////////////////////////////// INITIALIZATION ////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

$baseURL = 'http://'.(isset($_SERVER['HTTP_HOST']) && isset($_SERVER['PHP_SELF']) ? preg_replace('/(.*)index\.php$/', '$1', $_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF']) : '/');
$loginURL = 'http://my.bzflag.org/weblogin.php?url='.urlencode($baseURL.'?action=login&callsign=%USERNAME%&token=%TOKEN%');

// try to initialize the database
$databaseUp = FALSE;
if(file_exists('config.php')) {
	include('config.php');
	$mysqli = @new mysqli(NULL, $mySQLUsername, $mySQLPassword, $mySQLDatabase);
	if(! $mysqli->connect_errno) {
		$queryResult = $mysqli->query('SHOW TABLES');
		if($queryResult && $queryResult->num_rows >= 6) {
			$databaseUp = TRUE;
			$mysqli->set_charset('utf8');
			$tables = $queryResult->fetch_all();
			foreach(Array('config', 'events', 'teams', 'users', 'results') as $table) {
				$containsTable = FALSE;
				for($i = 0; $i < count($tables); ++$i) if($tables[$i][0] == $mySQLPrefix.$table) $containsTable = TRUE;
				if(! $containsTable) $databaseUp = FALSE;
			}
		}
	}
}

// update any outdated table structure
if($databaseUp) {
	$queryResult = $mysqli->query('DESCRIBE '.$mySQLPrefix.'teams'); // default value of teams->sufficiencyTime was changed from '0000-00-00 00:00:00' to NULL
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = NULL;
		do {
			$resultArray = $queryResult->fetch_assoc();
		} while($resultArray != NULL && $resultArray['Field'] != 'sufficiencyTime');
		if($resultArray['Field'] == 'sufficiencyTime')
			if($resultArray['Null'] != 'YES' || $resultArray['Default'] != '')
				$mysqli->query('ALTER TABLE teams MODIFY COLUMN sufficiencyTime TIMESTAMP NULL');
	}
	$queryResult = $mysqli->query('SELECT id FROM '.$mySQLPrefix.'teams WHERE sufficiencyTime="0000-00-00 00:00:00"'); // there may have been rows with the old default '0000-00-00 00:00:00' value for sufficiencyTime
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
		foreach($resultArray as $row)
			$mysqli->query('UPDATE '.$mySQLPrefix.'teams SET sufficiencyTime=NULL WHERE id='.$row['id']);
	}
}

// check for site configuration in database
$configUp = FALSE;
if($databaseUp) {
	$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'config');
	if($queryResult && $queryResult->num_rows > 0)
		$configUp = TRUE;
}

// check for at least one event
$currentEvent = 0;
if($configUp) {
	$queryResult = $mysqli->query('SELECT MAX(id) FROM '.$mySQLPrefix.'events');
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_row();
		$currentEvent = $resultArray[0];
	}
}

// check whether the current event is closed
$eventClosed = FALSE;
if($currentEvent) {
	$queryResult = $mysqli->query('SELECT isClosed FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent);
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_assoc();
		if($resultArray['isClosed'])
			$eventClosed = TRUE;
	}
}

session_start();

// hardcoded user spoofing
//$_SESSION['bzid'] = 9972;
//$_SESSION['callsign'] = 'macsforme';
//$_SESSION['groups'] = Array('FAIRSERVE.TECH');
//unset($_SESSION['groups']);

// check for admin status (actions manipulating this will refresh the page)
$isAdmin = FALSE;
if(array_key_exists('bzid', $_SESSION)) {
	$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'config');
	if($queryResult && $queryResult->num_rows == 0) {
		$isAdmin = TRUE;
	} else {
		if(isset($_SESSION['groups'])) {
			$queryResult = $mysqli->query('SELECT adminGroups from '.$mySQLPrefix.'config');
			if($queryResult && $queryResult->num_rows > 0) {
				$resultArray = $queryResult->fetch_row();
				foreach(explode(',', $resultArray[0]) as $group)
					foreach($_SESSION['groups'] as $memberGroup)
						if($group == $memberGroup)
							$isAdmin = TRUE;
			}
		}
	}
}

///////////////////////////////////////////////////////////////////////////////
////////////////////////////// PRE-CONTENT LOGIC //////////////////////////////
///////////////////////////////////////////////////////////////////////////////

//////////////////////////////// Action Logic /////////////////////////////////

// error message that can be set by any logic
$error = FALSE;

// main action switch
switch(array_key_exists('action', $_GET) ? $_GET['action'] : '') {
case 'login':
	if($databaseUp) {
		$validationURL = 'http://my.bzflag.org/db/?action=CHECKTOKENS&checktokens='.urlencode($_GET['callsign']).'%3D'.$_GET['token'];
		if($configUp) {
			$queryResult = $mysqli->query('SELECT adminGroups FROM '.$mySQLPrefix.'config');
			if($queryResult && $queryResult->num_rows > 0) {
				$resultArray = $queryResult->fetch_row();
				$validationURL .= '&groups='.implode('%0D%0A', explode(',', $resultArray[0]));
			}
			if($currentEvent) {
				$queryResult = $mysqli->query('SELECT memberGroups FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent);
				if($queryResult && $queryResult->num_rows > 0) {
					$resultArray = $queryResult->fetch_row();
					if(strlen($resultArray[0]) > 0)
						$validationURL .= '%0D%0A'.implode('%0D%0A', explode(',', $resultArray[0]));
				}
			}
		}
		$validationResponse = trim(file_get_contents($validationURL));
		$validationResponse = str_replace("\r\n", "\n", $validationResponse);
		$validationResponse = str_replace("\r", "\n", $validationResponse);
		$validationResponse = explode("\n", $validationResponse);
		if(preg_match('/^TOKGOOD/', $validationResponse[1]) != FALSE) {
			$matches = Array(); preg_match('/^BZID:\s(\d+)\s(.*)$/', $validationResponse[2], $matches);
			if(is_numeric($matches[1])) {
				$resultArray = Array();
				$queryResult = $mysqli->query('SELECT bzid FROM '.$mySQLPrefix.'users');
				if($queryResult && $queryResult->num_rows > 0)
					$resultArray = $queryResult->fetch_assoc();
				if($configUp || $queryResult->num_rows == 0 || ($queryResult->num_rows == 1 && $resultArray['bzid'] == $matches[1])) {
					$groups = preg_split('/\:/', substr($validationResponse[1], 9));
					array_shift($groups);
					$_SESSION['groups'] = $groups;
					if($configUp && (count($_SESSION['groups']) == 0 || (count($_SESSION['groups']) == 1 && $_SESSION['groups'][0] == ''))) {
						unset($_SESSION['bzid']);
						header('Location: '.$baseURL.'?action=loginnotpermitted');
						exit;
					}
					$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'users WHERE bzid='.$matches[1]);
					if($queryResult && $queryResult->num_rows > 0) {
						$queryResult = $mysqli->query('UPDATE '.$mySQLPrefix.'users SET callsign="'.$mysqli->real_escape_string($matches[2]).'" WHERE bzid='.$matches[1]);
						if($queryResult) {
							$_SESSION['bzid'] = $matches[1];
							$_SESSION['callsign'] = $matches[2];
						}
					} else {
						$queryResult = $mysqli->query('INSERT INTO '.$mySQLPrefix.'users (bzid,callsign) VALUES ('.$matches[1].',"'.$mysqli->real_escape_string($matches[2]).'")');
						if($queryResult) {
							$_SESSION['bzid'] = $matches[1];
							$_SESSION['callsign'] = $matches[2];
						}
					}
				}
			}
		}
	}
	header('Location: '.$baseURL);
	exit;

case 'logout':
	unset($_SESSION['bzid']);
	header('Location: '.$baseURL);
	exit;

case 'loginnotpermitted':
	$error = "You are not permitted to log in here. Please ensure that you are a member of a BZFlag global group that is participating in this event.";
	break;

case 'abandonteam':
	if($configUp && array_key_exists('bzid', $_SESSION) && ! $eventClosed) {
		$queryResult = $mysqli->query('SELECT team FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')');
		if($queryResult && $queryResult->num_rows > 0) {
			$resultArray = $queryResult->fetch_assoc();
			$team = $resultArray['team'];
			$mysqli->query('DELETE FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND team='.$team);
			$queryResult = $mysqli->query('SELECT COUNT(*) AS memberCount,(SELECT minTeamSize FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent.') AS minTeamSize FROM '.$mySQLPrefix.'memberships WHERE '.$mySQLPrefix.'memberships.team='.$team.' AND '.$mySQLPrefix.'memberships.rating IS NOT NULL');
			if($queryResult && $queryResult->num_rows > 0) {
				$resultArray = $queryResult->fetch_assoc();
				if($resultArray['memberCount'] < $resultArray['minTeamSize'])
					$mysqli->query('UPDATE '.$mySQLPrefix.'teams SET sufficiencyTime=NULL WHERE id='.$team);
			}
			$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'memberships WHERE team='.$team.' AND rating IS NOT NULL');
			if(! $queryResult || $queryResult->num_rows == 0) {
				$mysqli->query('DELETE FROM '.$mySQLPrefix.'memberships WHERE team='.$team);
				$mysqli->query('DELETE FROM '.$mySQLPrefix.'teams WHERE id='.$team);
			}
		}
	}
	break;

case 'createteam':
	if($configUp && array_key_exists('bzid', $_SESSION) && ! $eventClosed) {
		if(isset($_POST['bzids']) && is_array($_POST['bzids'])) {
			$bzids = Array($_SESSION['bzid']); foreach($_POST['bzids'] as $bzid) if(is_numeric($bzid)) array_push($bzids, $bzid);
			$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'memberships WHERE (bzid='.implode(' OR bzid=', $bzids).') AND rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')');
			if(! $queryResult || $queryResult->num_rows == 0) {
				$mysqli->query('DELETE FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND rating IS NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')');
				$queryResult = $mysqli->query('SELECT maxTeamSize FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent);
				if($queryResult && $queryResult->num_rows > 0) {
					$resultArray = $queryResult->fetch_assoc();
					$myRating = 0;
					$ranksArray = getRatings(Array($_SESSION['bzid']));
					if(array_key_exists($_SESSION['bzid'], $ranksArray))
						$myRating = $ranksArray[$_SESSION['bzid']];
					$mysqli->query('INSERT INTO '.$mySQLPrefix.'teams SET event='.$currentEvent);
					foreach($bzids as $bzid) {
						$mysqli->query('INSERT INTO '.$mySQLPrefix.'memberships SET team=(SELECT MAX(id) FROM '.$mySQLPrefix.'teams),bzid='.$bzid.($bzid == $_SESSION['bzid'] ? ',rating='.$myRating : ''));
					}
				}
			} else {
				$error = "One of the players you selected already started or joined another team. Please try again.";
			}
		} else {
			$error = "Please select at least one other player to invite to your new team.";
		}
	}
	break;

case 'addmembers':
	if($configUp && array_key_exists('bzid', $_SESSION) && ! $eventClosed) {
		if(isset($_POST['bzids']) && is_array($_POST['bzids'])) {
			$queryResult = $mysqli->query('SELECT team FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')');
			if($queryResult && $queryResult->num_rows > 0) {
				$resultArray = $queryResult->fetch_assoc();
				$team = $resultArray['team'];
				$queryResult = $mysqli->query('SELECT COUNT(*) AS memberCount,(SELECT maxTeamSize FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent.') AS maxTeamSize FROM '.$mySQLPrefix.'memberships WHERE '.$mySQLPrefix.'memberships.team='.$team.' AND '.$mySQLPrefix.'memberships.rating IS NOT NULL');

				if($queryResult && $queryResult->num_rows > 0) {
					$resultArray = $queryResult->fetch_assoc();
					if($resultArray['memberCount'] < $resultArray['maxTeamSize']) {
						$bzids = Array(); foreach($_POST['bzids'] as $bzid) if(is_numeric($bzid)) array_push($bzids, $bzid);
						$queryResult = $mysqli->query('SELECT bzid FROM '.$mySQLPrefix.'memberships WHERE rating IS NOT NULL AND team in (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.') AND (bzid='.implode(' OR bzid=', $bzids).')');
						if(! $queryResult || $queryResult->num_rows == 0) {
							foreach($bzids as $bzid) {
								$resultArray = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'memberships WHERE bzid='.$bzid.' AND ((rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')) OR (rating IS NULL AND team=(SELECT team FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.'))))');
								if(! $resultArray || $resultArray->num_rows == 0)
									$mysqli->query('INSERT INTO '.$mySQLPrefix.'memberships set team='.$team.',bzid='.$bzid);
							}
						} else {
							$error = "One of the players you selected already started or joined another team. Please try again.";
						}
					}
				}
			}
		} else {
			$error = "Please select at least one player to invite to your team.";
		}
	}
	break;

case 'acceptinvitation':
	if($configUp && array_key_exists('bzid', $_SESSION) && ! $eventClosed) {
		if(isset($_POST['team']) && is_numeric($_POST['team'])) {
			$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND team='.$_POST['team'].' AND rating IS NULL AND team IN (SELECT id FROM teams WHERE event='.$currentEvent.')');
			if($queryResult && $queryResult->num_rows > 0) {
				$myRating = 0;
				$ranksArray = getRatings(Array($_SESSION['bzid']));
				if(array_key_exists($_SESSION['bzid'], $ranksArray))
					$myRating = $ranksArray[$_SESSION['bzid']];
				$mysqli->query('UPDATE '.$mySQLPrefix.'memberships SET rating='.$myRating.' WHERE team='.$_POST['team'].' AND bzid='.$_SESSION['bzid']);
				$mysqli->query('DELETE FROM '.$mySQLPrefix.'memberships WHERE team <> '.$_POST['team'].' AND bzid='.$_SESSION['bzid'].' AND team IN (SELECT id FROM teams WHERE event='.$currentEvent.')');
				$queryResult = $mysqli->query('SELECT (SELECT COUNT(*) FROM '.$mySQLPrefix.'memberships WHERE team='.$_POST['team'].' AND rating IS NOT NULL) AS existingMembers,minTeamSize,maxTeamSize FROM events WHERE events.id='.$currentEvent);
				if($queryResult && $queryResult->num_rows > 0) {
					$resultArray = $queryResult->fetch_assoc();
					if($resultArray['existingMembers'] == $resultArray['minTeamSize'])
						$mysqli->query('UPDATE '.$mySQLPrefix.'teams SET sufficiencyTime="'.date("Y-m-d H:i:s", time()).'" WHERE id='.$_POST['team']);
					if($resultArray['existingMembers'] == $resultArray['maxTeamSize'])
						$mysqli->query('DELETE FROM '.$mySQLPrefix.'memberships WHERE team='.$_POST['team'].' AND rating IS NULL');
				}
			}
		} else {
			$error = "Please select an invitation to accept.";
		}
	}
	break;

case 'config':
	if($isAdmin) {
		$groups = preg_split('/\s/', urldecode(strtoupper($_POST['groups'])), NULL, PREG_SPLIT_NO_EMPTY);
		$groups = preg_split('/\,/', implode(',', $groups), NULL, PREG_SPLIT_NO_EMPTY);
		$groups = implode(',', $groups);
		$queryResult = $mysqli->query('INSERT INTO '.$mySQLPrefix.'config (adminGroups) VALUES ("'.$mysqli->real_escape_string($groups).'")');
		if($queryResult) {
			unset($_SESSION['bzid']);
			header('Location: '.$loginURL);
			exit;
		}
	}
	break;

case 'closeevent':
case 'openevent':
	$queryResult = $mysqli->query('SELECT COUNT(*) FROM '.$mySQLPrefix.'results WHERE event='.$currentEvent);
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_row();
		if($resultArray[0] > 0)
			break;
	}
	if($isAdmin && $currentEvent)
		$mysqli->query('UPDATE '.$mySQLPrefix.'events set isClosed=NOT isClosed WHERE id='.$currentEvent);
	$eventClosed = ! $eventClosed;
	if($_GET['action'] == 'openevent')
		break;

case 'updateseeding':
	$queryResult = $mysqli->query('SELECT COUNT(*) FROM '.$mySQLPrefix.'results WHERE event='.$currentEvent);
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_row();
		if($resultArray[0] > 0)
			break;
	}
	if($isAdmin && $configUp) {
		$queryResult = $mysqli->query('SELECT bzid FROM '.$mySQLPrefix.'memberships WHERE rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')');
		if($queryResult && $queryResult->num_rows > 0) {
			$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
			$bzids = Array(); foreach($resultArray as $result) array_push($bzids, $result['bzid']);
			$ranksArray = getRatings($bzids);
			foreach($ranksArray as $bzid => $rating)
				$mysqli->query('UPDATE '.$mySQLPrefix.'memberships SET rating='.$rating.' WHERE bzid='.$bzid.' AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')');
		}
	}
	break;

case 'deleteevent':
	if($isAdmin && $currentEvent) {
		$mysqli->query('DELETE FROM '.$mySQLPrefix.'results WHERE event='.$currentEvent);
		$mysqli->query('DELETE FROM '.$mySQLPrefix.'memberships WHERE team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')');
		$mysqli->query('DELETE FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent);
		$mysqli->query('DELETE FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent);
		$mysqli->query('UPDATE '.$mySQLPrefix.'users SET lastEvent=NULL WHERE lastEvent NOT IN (SELECT id FROM '.$mySQLPrefix.'events)');
	}
	header('Location: '.$baseURL.'?action=admin'); // our $currentEvent is now thoroughly screwed, so start over
	exit;

case 'editevent':
	if($isAdmin && $configUp && isset($_POST['description']) && $_POST['description'] != "" && is_numeric($_POST['maxTeams']) && is_numeric($_POST['minTeamSize']) && is_numeric($_POST['maxTeamSize']) && is_numeric($_POST['month']) && is_numeric($_POST['day']) && is_numeric($_POST['year']) && is_numeric($_POST['hour']) && is_numeric($_POST['minute']) && is_numeric($_POST['registrationBuffer']) && isset($_POST['memberGroups']) && $_POST['memberGroups'] != "") {
		$groups = preg_split('/\s/', urldecode(strtoupper($_POST['memberGroups'])), NULL, PREG_SPLIT_NO_EMPTY);
		$groups = preg_split('/\,/', implode(',', $groups), NULL, PREG_SPLIT_NO_EMPTY);
		$groups = implode(',', $groups);
		if($_POST['existing']) {
			$registrationsExist = FALSE;
			$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent);
			if($queryResult && $queryResult->num_rows > 0)
				$registrationsExist = TRUE;
			$queryResult = $mysqli->query('SELECT maxTeams FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent);
			if($queryResult && $queryResult->num_rows > 0) {
				$resultArray = $queryResult->fetch_assoc();
				if($queryResult && $queryResult->num_rows > 0) {
					if($resultArray['maxTeams'] != $_POST['maxTeams']) {
						$priorMaxTeams = $resultArray['maxTeams'];
						$queryResult = $mysqli->query('SELECT matchNumber FROM '.$mySQLPrefix.'results WHERE event='.$currentEvent);
						if($queryResult && $queryResult->num_rows > 0) {
							$error = "The maximum team count cannot be changed after results have been entered.";
							$_POST['maxTeams'] = $priorMaxTeams;
						}
					}
				}
				$mysqli->query('UPDATE '.$mySQLPrefix.'events SET description="'.$mysqli->real_escape_string($_POST['description']).'",maxTeams='.$_POST['maxTeams'].(! $registrationsExist ? ',minTeamSize='.$_POST['minTeamSize'].',maxTeamSize='.$_POST['maxTeamSize'] : '').',startTime="'.date("Y-m-d H:i:s", strtotime($_POST['year'].'-'.$_POST['month'].'-'.$_POST['day'].' '.$_POST['hour'].':'.$_POST['minute'].':00')).'",registrationBuffer='.$_POST['registrationBuffer'].',memberGroups="'.$mysqli->real_escape_string($groups).'" WHERE id='.$currentEvent);
			}
		} else {
			$mysqli->query('INSERT INTO '.$mySQLPrefix.'events SET description="'.$mysqli->real_escape_string($_POST['description']).'",maxTeams='.$_POST['maxTeams'].',minTeamSize='.$_POST['minTeamSize'].',maxTeamSize='.$_POST['maxTeamSize'].',startTime="'.date("Y-m-d H:i:s", strtotime($_POST['year'].'-'.$_POST['month'].'-'.$_POST['day'].' '.$_POST['hour'].':'.$_POST['minute'].':00')).'",registrationBuffer='.$_POST['registrationBuffer'].',memberGroups="'.$mysqli->real_escape_string($groups).'"');
			header('Location: '.$baseURL.'?action=admin'); // our $currentEvent is now thoroughly screwed, so start over
			exit;
		}
	} else {
		$error = "Event information contained malformed data.";
	}
	break;

case 'spoof':
	if($isAdmin && $configUp && isset($_POST['bzid']) && is_numeric($_POST['bzid'])) {
		$queryResult = $mysqli->query('SELECT callsign FROM '.$mySQLPrefix.'users WHERE bzid='.$_POST['bzid']);
		if($queryResult && $queryResult->num_rows > 0) {
			$resultArray = $queryResult->fetch_assoc();
			$_SESSION['bzid'] = $_POST['bzid'];
			$_SESSION['callsign'] = $resultArray['callsign'];
			unset($_SESSION['groups']);
			header('Location: '.$baseURL);
			exit;
		}
	}
	break;

case 'ban':
	if($isAdmin && $configUp && isset($_POST['bzid']) && is_numeric($_POST['bzid']))
		$mysqli->query('UPDATE '.$mySQLPrefix.'users set banned=TRUE WHERE bzid='.$_POST['bzid']);
	break;

case 'unban':
	if($isAdmin && $configUp && isset($_POST['bzid']) && is_numeric($_POST['bzid']))
		$mysqli->query('UPDATE '.$mySQLPrefix.'users set banned=FALSE WHERE bzid='.$_POST['bzid']);
	break;

case 'admingroups':
	if($isAdmin && $configUp) {
		$groups = preg_split('/\s/', urldecode(strtoupper($_POST['groups'])), NULL, PREG_SPLIT_NO_EMPTY);
		$groups = preg_split('/\,/', implode(',', $groups), NULL, PREG_SPLIT_NO_EMPTY);
		$groups = implode(',', $groups);
		$mysqli->query('UPDATE '.$mySQLPrefix.'config set adminGroups="'.$mysqli->real_escape_string($groups).'"');
		header('Location: '.$baseURL.'?action=admin');
		exit;
	}
	break;

case 'promptenterresult':
case 'enterresult':
	if(! $isAdmin || ! is_numeric($_POST['match']) || ! $eventClosed) {
		header('Location: '.$baseURL);
		exit;
	}
	$queryResult = $mysqli->query('SELECT maxTeams FROM '.$mySQLPrefix.'events WHERE maxTeams >= '.$_POST['match'].' + 1 AND id='.$currentEvent);
	if(! $queryResult || $queryResult->num_rows == 0) {
		header('Location: '.$baseURL);
		exit;

	}
	$queryResult = $mysqli->query('SELECT COUNT(*) FROM '.$mySQLPrefix.'teams WHERE sufficiencyTime IS NOT NULL AND event='.$currentEvent);
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_row();
		if($_POST['match'] >= $resultArray[0]) {
			header('Location: '.$baseURL);
			exit;
		}
	}
	if($_GET['action'] == 'enterresult') {
		if(! isset($_POST['team1Score']) || ! isset($_POST['team2Score']) || ! isset($_POST['disqualifyTeam']) || ! is_numeric($_POST['disqualifyTeam'])) {
			header('Location: '.$baseURL);
			exit;
		}
		if(! is_numeric($_POST['team1Score']) || ! is_numeric($_POST['team2Score'])) {
			$error = "Both team scores must be specified. Use zeros if a team was disqualified before the match was played.";
		} else {
			$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'results WHERE matchNumber='.getDependentMatchNumber($_POST['match']).' AND event='.$currentEvent);
			if(! $queryResult || $queryResult->num_rows == 0) {
				$mysqli->query('DELETE FROM '.$mySQLPrefix.'results WHERE matchNumber='.$_POST['match'].' AND event='.$currentEvent);
				$mysqli->query('INSERT INTO '.$mySQLPrefix.'results SET matchNumber='.$_POST['match'].',team1Score='.$_POST['team1Score'].',team2Score='.$_POST['team2Score'].',disqualifyTeam='.$_POST['disqualifyTeam'].',event='.$currentEvent);
			} else {
				$error = "This match result cannot be updated because a dependent match result from the next bracket has already been entered.";
			}
		}
	}
	break;

case 'deleteresult':
	if(! $isAdmin || ! is_numeric($_POST['match'])) {
		header('Location: '.$baseURL);
		exit;
	}
	$mysqli->query('DELETE FROM '.$mySQLPrefix.'results WHERE matchNumber='.$_POST['match'].' AND event='.$currentEvent);
	break;
}

////////////////////////////// Post-Action Logic //////////////////////////////

// unauthenticated and unconfigured redirect
if($databaseUp && ! array_key_exists('bzid', $_SESSION) && (! $configUp)) {
	header('Location: '.$loginURL);
	exit;
}

// ban check
if($configUp && array_key_exists('bzid', $_SESSION)) {
	$queryResult = $mysqli->query('SELECT banned FROM '.$mySQLPrefix.'users WHERE bzid='.$_SESSION['bzid']);
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_assoc();
		if($resultArray['banned']) {
			unset($_SESSION['bzid']);
			header('Location: '.$baseURL);
			exit;
		}
	}
}

// touch the last event record for the current logged-in user
if(array_key_exists('bzid', $_SESSION) && $currentEvent)
	$mysqli->query('UPDATE '.$mySQLPrefix.'users SET lastEvent='.$currentEvent.' WHERE bzid='.$_SESSION['bzid']);

///////////////////////////////////////////////////////////////////////////////
/////////////////////////////// CONTENT OUTPUT ////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

/////////////////////////////// Content Header ////////////////////////////////

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01//EN\" \"http://www.w3.org/TR/html4/strict.dtd\">\n";
echo "<html>\n";
echo "\t<head>\n";
echo "\t\t<meta http-equiv=\"Content-type\" content=\"text/html;charset=UTF-8\">\n";
echo "\t\t<link rel=\"stylesheet\" type=\"text/css\" href=\"styles.css\">\n";
echo "\t\t<title>BZ FM Challenge</title>\n";
echo "\t</head>\n";
echo "\t<body>\n";
echo "\t\t<div class=\"headerBox\">\n";
echo "\t\t\t<img src=\"bzicon.png\" alt=\"icon\">\n";
echo "\t\t\tBZFLAG FUNMATCH CHALLENGE\n";
echo "\t\t</div>\n";
echo "\t\t<ul class=\"headerButtons\">\n";
echo "\t\t\t<li><a ".(((! array_key_exists('action', $_GET) && ! array_key_exists('event', $_GET)) || array_key_exists('action', $_GET) && ($_GET['action'] == 'promptenterresult' || $_GET['action'] == 'enterresult' || $_GET['action'] == 'deleteresult')) ? "class=\"current\" " : "")."href=\".\">HOME</a></li>";
echo "<li><a ".(array_key_exists('action', $_GET) && $_GET['action'] == 'info' ? "class=\"current\" " : "")."href=\"?action=info\">INFORMATION</a></li>";
if(array_key_exists('bzid', $_SESSION))
	echo "<li><a ".(array_key_exists('action', $_GET) && ($_GET['action'] == 'registration' || $_GET['action'] == 'abandonteam' || $_GET['action'] == 'createteam' || $_GET['action'] == 'acceptinvitation' || $_GET['action'] == 'addmembers') ? "class=\"current\" " : "")."href=\"?action=registration\">REGISTRATION</a></li>";
if($currentEvent) {
	$queryResult = $mysqli->query('SELECT COUNT(id) FROM '.$mySQLPrefix.'events WHERE isClosed=TRUE AND id<>'.$currentEvent);
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_row();
		if($resultArray[0] > 0)
			echo "<li><a ".((array_key_exists('action', $_GET) && $_GET['action'] == 'history') || array_key_exists('event', $_GET) ? "class=\"current\" " : "")."href=\"?action=history\">PAST EVENTS</a></li>";
	}
}
if($isAdmin) echo "<li><a ".(array_key_exists('action', $_GET) && ($_GET['action'] == 'admin' || $_GET['action'] == 'promptcreateevent' || $_GET['action'] == 'prompteditevent' || $_GET['action'] == 'promptdeleteevent' || $_GET['action'] == 'closeevent' || $_GET['action'] == 'openevent' || $_GET['action'] == 'deleteevent' || $_GET['action'] == 'editevent' || $_GET['action'] == 'updateseeding' || $_GET['action'] == 'ban' || $_GET['action'] == 'unban' || $_GET['action'] == 'admingroups') ? "class=\"current\" " : "")."href=\"?action=admin\">ADMIN</a></li>";
echo "<li><a href=\"http://forums.bzflag.org/ucp.php?i=pm&amp;mode=compose&amp;u=9972\">CONTACT</a></li>";
if(array_key_exists('bzid', $_SESSION))
	echo "<li><a href=\"?action=logout\">LOG OUT</a></li>";
else
	echo "<li><a href=\"".$loginURL."\">LOG IN</a></li>";

echo "\n\t\t</ul>\n";
echo "\t\t<div class=\"mainContent\">\n";

//////////////////////////////// Main Content /////////////////////////////////

// errors first
if($error)
	echo "\t\t\t<p class=\"error\"><b>ERROR:</b> ".$error."</p>\n";

// main action logic
if(array_key_exists('bzid', $_SESSION) && $configUp && array_key_exists('action', $_GET) && ($_GET['action'] == 'registration' || $_GET['action'] == 'abandonteam' || $_GET['action'] == 'createteam' || $_GET['action'] == 'acceptinvitation' || $_GET['action'] == 'addmembers')) {
	if(! $currentEvent) {
		echo "\t\t\t<h1>User Information</h1>\n";
		echo "\t\t\t<table>\n";
		echo "\t\t\t\t<tr><td class=\"rightalign\"><b>Callsign:</b></td><td class=\"leftalign\">".$_SESSION['callsign']."</td></tr>\n";
		echo "\t\t\t</table>\n";
	} else {
		$teamMembers = '';
		$enough = FALSE;
		$teamWaitlisted = TRUE;
		$queryResult = $mysqli->query('SELECT '.$mySQLPrefix.'memberships.team,'.$mySQLPrefix.'users.callsign,'.$mySQLPrefix.'memberships.rating IS NOT NULL AS accepted,((SELECT COUNT(*) FROM '.$mySQLPrefix.'memberships WHERE rating IS NOT NULL AND team=(SELECT team FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.'))) >= (SELECT minTeamSize FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent.')) AS enough,(SELECT ((SELECT COUNT(*) FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.' AND sufficiencyTime IS NOT NULL AND sufficiencyTime <= (SELECT sufficiencyTime FROM '.$mySQLPrefix.'teams WHERE id=(SELECT team FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.' ORDER BY sufficiencyTime)))) > (SELECT maxTeams FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent.'))) AS waitlisted FROM '.$mySQLPrefix.'users,'.$mySQLPrefix.'memberships WHERE '.$mySQLPrefix.'users.bzid = '.$mySQLPrefix.'memberships.bzid AND '.$mySQLPrefix.'memberships.bzid IN (SELECT bzid FROM '.$mySQLPrefix.'memberships WHERE team=(SELECT team FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.'))) AND '.$mySQLPrefix.'memberships.team=(SELECT team FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')) ORDER BY accepted DESC, callsign');
		if($queryResult && $queryResult->num_rows > 0) {
			$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
			if($resultArray[0]['enough'])
				$enough = TRUE;
			if(! $resultArray[0]['waitlisted'])
				$teamWaitlisted = FALSE;
			foreach($resultArray as $result)
				$teamMembers .= ($teamMembers != '' ? ', ' : '').($result['accepted'] ? '' : '<span class="gray">').$result['callsign'].($result['accepted'] ? '' : '</span>');
		}
		echo "\t\t\t<h1>User & Team Information</h1>\n";
		echo "\t\t\t<table>\n";
		echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Callsign:</b></td><td class=\"leftAlign\">".$_SESSION['callsign']."</td></tr>\n";
		echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Team Status:</b></td><td class=\"leftAlign\">".($teamMembers == '' ? 'Teamless' : (! $enough ? 'Insufficient Members' : ($teamWaitlisted ? 'On Waiting List' : 'Qualified')))."</td></tr>\n";
		if($teamMembers != '') echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Team Members:</b></td><td class=\"leftAlign\">".$teamMembers."</td></tr>\n";
		echo "\t\t\t</table>\n";
		if($teamMembers != '' && ! $eventClosed) {
			echo "\t\t\t<form action=\".\" method=\"GET\"><p><input type=\"hidden\" name=\"action\" value=\"promptabandonteam\"><input type=\"submit\" value=\"Abandon Team\" class=\"submitButton\"></p></form>\n";
			$queryResult = $mysqli->query('SELECT '.$mySQLPrefix.'memberships.team,'.$mySQLPrefix.'events.maxTeamSize FROM '.$mySQLPrefix.'memberships,'.$mySQLPrefix.'events WHERE '.$mySQLPrefix.'memberships.team=(SELECT team FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')) AND '.$mySQLPrefix.'memberships.rating IS NOT NULL AND '.$mySQLPrefix.'events.id='.$currentEvent);
			if($queryResult && $queryResult->num_rows > 0) {
				$resultArray = $queryResult->fetch_assoc();
				if($queryResult->num_rows < $resultArray['maxTeamSize']) {
					echo "\t\t\t<form action=\".?action=addmembers\" method=\"POST\">\n";
					echo "\t\t\t\t<fieldset>\n";
					echo "\t\t\t\t\t<legend>Invite New Member(s)</legend>\n";
					$queryResult = $mysqli->query('SELECT bzid,callsign FROM '.$mySQLPrefix.'users WHERE '.$mySQLPrefix.'users.bzid NOT IN (SELECT bzid FROM '.$mySQLPrefix.'memberships WHERE rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')) AND bzid NOT IN (SELECT bzid FROM '.$mySQLPrefix.'memberships WHERE team='.$resultArray['team'].') AND lastEvent='.$currentEvent.' ORDER BY callsign');
					if($queryResult && $queryResult->num_rows > 0) {
						$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
						echo "\t\t\t\t\tPlayers:\n";
						echo "\t\t\t\t\t<select name=\"bzids[]\" multiple>\n";
						foreach($resultArray as $user)
							echo "\t\t\t\t\t\t<option value=\"".$user['bzid']."\">".$user['callsign']."</option>\n";
						echo "\t\t\t\t\t</select>\n";
						echo "\t\t\t\t\t<i>(hold down ctrl/command to select multiple)</i>\n";
						echo "\t\t\t\t\t<br>\n";
						echo "\t\t\t\t\t<br>\n";
						echo "\t\t\t\t\t<input type=\"submit\" value=\"Invite\" class=\"submitButton\">\n";
					} else {
						echo "\t\t\t\t\t<i>No teamless players available.</i>\n";
					}
					echo "\t\t\t\t</fieldset>\n";
					echo "\t\t\t</form>\n";
				}
			}
		} else if (! $eventClosed) {
			$queryResult = $mysqli->query('SELECT team FROM '.$mySQLPrefix.'memberships WHERE bzid='.$_SESSION['bzid'].' AND rating IS NULL AND team IN (SELECT id FROM teams WHERE event='.$currentEvent.')');
			if($queryResult && $queryResult->num_rows > 0) {
				echo "\t\t\t<form action=\".?action=acceptinvitation\" method=\"POST\">\n";
				echo "\t\t\t\t<fieldset>\n";
				echo "\t\t\t\t\t<legend>Invitations</legend>\n";
				$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
				foreach($resultArray as $team) {
					$queryResult = $mysqli->query('SELECT callsign FROM '.$mySQLPrefix.'users WHERE bzid IN (SELECT bzid FROM '.$mySQLPrefix.'memberships WHERE team='.$team['team'].' AND rating IS NOT NULL) ORDER BY callsign');
					if($queryResult && $queryResult->num_rows > 0) {
						$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
						$names = Array();
						foreach($resultArray as $name)
							array_push($names, $name['callsign']);
						$names = implode(', ',$names);
						echo "\t\t\t\t\t<input type=\"radio\" name=\"team\" value=\"".$team['team']."\"> ".$names."<br>\n";
					}
				}
				echo "\t\t\t\t\t<br>\n";
				echo "\t\t\t\t\t<input type=\"submit\" value=\"Accept Invitation\" class=\"submitButton\">\n";
				echo "\t\t\t\t</fieldset>\n";
				echo "\t\t\t</form>\n";
			}
			echo "\t\t\t<form action=\".?action=createteam\" method=\"POST\">\n";
			echo "\t\t\t\t<fieldset>\n";
			echo "\t\t\t\t\t<legend>Create Team</legend>\n";
			$queryResult = $mysqli->query('SELECT '.$mySQLPrefix.'users.bzid,'.$mySQLPrefix.'users.callsign,'.$mySQLPrefix.'events.maxTeamSize FROM '.$mySQLPrefix.'users,'.$mySQLPrefix.'events WHERE '.$mySQLPrefix.'users.bzid NOT IN (SELECT bzid FROM '.$mySQLPrefix.'memberships WHERE rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.')) AND bzid<>'.$_SESSION['bzid'].' AND '.$mySQLPrefix.'events.id='.$currentEvent.' AND lastEvent='.$currentEvent.' ORDER BY '.$mySQLPrefix.'users.callsign');
			if($queryResult && $queryResult->num_rows > 0) {
				$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
				echo "\t\t\t\t\tPlayers:\n";
				echo "\t\t\t\t\t<select name=\"bzids[]\" multiple>\n";
				foreach($resultArray as $user)
					echo "\t\t\t\t\t\t<option value=\"".$user['bzid']."\">".$user['callsign']."</option>\n";
				echo "\t\t\t\t\t</select>\n";
				echo "\t\t\t\t\t<i>(hold down ctrl/command to select multiple; maximum team size is ".$resultArray[0]['maxTeamSize'].")</i>\n";
				echo "\t\t\t\t\t<br>\n";
				echo "\t\t\t\t\t<br>\n";
				echo "\t\t\t\t\t<input type=\"submit\" value=\"Create Team\" class=\"submitButton\">\n";
			} else {
				echo "\t\t\t\t\t<i>No other teamless players available.</i>\n";
			}
			echo "\t\t\t\t</fieldset>\n";
			echo "\t\t\t</form>\n";
		}
	}
} else if($configUp && array_key_exists('action', $_GET) && $_GET['action'] == 'info') {
	echo "\t\t\t<h1>Tournament Format</h1>\n";
	echo "\t\t\t<p>This event is a funmatch tournament for BZFlag league players. The competition format is a single-elimination table. To participate, you will need to group up with some other players (depending on the team sizes for the event) and register on this site as a team. Each of you must log in to this site to generate a player record. One of your team members must then create the team using the ".(array_key_exists('bzid', $_SESSION) ? "<a href=\".?action=registration\">registration</a>" : "registration")." page. The other team member(s) must then navigate to the same page and accept the team invitation. After the minimum team member count has been met, your team will be listed on the home page. Teams will be seeded (ranked) relative to other teams by the average of their <a href=\"http://leaguesunited.org\">Leagues United</a> individual player ratings. After the close of registration time, all teams are frozen, and all ratings are updated one last time to create the final seeding. A single-elimination bracket is then created, and teams are assigned to spots based on their seeding. Depending on how many teams register, some teams may have a bye into the second round. Teams will then compete against each assigned opponent until only one team remains, which will be declared the winner.</p>\n";
	echo "\t\t\t<h1>Rules</h1>\n";
	echo "\t\t\t<ul>\n";
	echo "\t\t\t\t<li>All standard league rules are in effect.</li>\n";
	echo "\t\t\t\t<li>There will be no ties in the initial team seeding. If two or more teams are rated equally, they will be seeded in order of registration time.</li>\n";
	echo "\t\t\t\t<li>Teams shall decide among themselves where matches shall take place. All official league match servers are available on a first come, first served basis.</li>\n";
	echo "\t\t\t\t<li>Matches shall be twenty minutes in length, except for the final match, which shall be thirty minutes in length. Once started, matches shall not be arbitrarily paused, but may be paused for technical issues affecting one or more players. Delays in matches should be avoided if possible because they affect matches in the next round.</li>\n";
	echo "\t\t\t\t<li>Teams that fail to appear for their scheduled match by the scheduled time will forfeit the match, and if it is their first match, they will be disqualified and will not be ranked in the final results. If fewer then the required number of team members appear for the match start, or if a team member leaves, becomes unresponsive, or is banned, the match shall be played with uneven teams until another team member is available. In the extreme scenario where no players from either team involved in a scheduled match arrive in time and the match cannot be played, the team with the lower seeding will be disqualified.</li>\n";
	echo "\t\t\t\t<li><b>In case of a tied score at the end of a match, the team with the lower ranking will advance and the team with the higher ranking will be eliminated.</b></li>\n";
	echo "\t\t\t\t<li>At the conclusion of each match, the winning team is responsible for reporting the result of the completed match to a league administrator. They will then check this site, or ask the reporting administrator, for their next match assignment.</li>\n";
	echo "\t\t\t\t<li>Any player who refuses to play an assigned opponent may cause his team to be disqualified and may be banned from future events. A player who causes serious disruption to the competition or who commits a serious violation of league or tournament rules during the competition may also be banned from future events.</li>\n";
	echo "\t\t\t\t<li>Any issues that arise which are not covered here will be adjudicated by any league administrator(s) present.</li>\n";
	echo "\t\t\t</ul>\n";
} else if($currentEvent && array_key_exists('action', $_GET) && $_GET['action'] == 'history') {
	echo "\t\t\t<p>\n";
	$queryResult = $mysqli->query('SELECT id,description FROM '.$mySQLPrefix.'events WHERE isClosed=TRUE AND id<>'.$currentEvent.' ORDER BY id DESC');
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
		for($i = 0; $i < count($resultArray); ++$i)
			echo "\t\t\t\t<a href=\"?event=".$resultArray[$i]['id']."\">".$resultArray[$i]['description']."</a>".($i < count($resultArray) - 1 ? "<br>" : "")."\n";
	}
	echo "\t\t\t</p>\n";
} else if(array_key_exists('bzid', $_SESSION) && $currentEvent && array_key_exists('action', $_GET) && $_GET['action'] == 'promptabandonteam') {
	echo "\t\t\t<h1>Abandon Team</h1>\n";
	echo "\t\t\t<p>Please confirm whether you wish to abandon your current team:</p>\n";
	echo "\t\t\t<table>\n";
	echo "\t\t\t\t<tr>\n";
	echo "\t\t\t\t\t<td><form action=\".\" method=\"GET\"><p class=\"tight\"><input type=\"hidden\" name=\"action\" value=\"registration\"><input type=\"submit\" value=\"Cancel\" class=\"submitButton\"></p></form></td>\n";
	echo "\t\t\t\t\t<td><form action=\".\" method=\"GET\"><p class=\"tight\"><input type=\"hidden\" name=\"action\" value=\"abandonteam\"><input type=\"submit\" value=\"Confirm\" class=\"submitButton\"></p></form></td>\n";
	echo "\t\t\t\t</tr>\n";
	echo "\t\t\t</table>\n";
} else if($isAdmin && $configUp && array_key_exists('action', $_GET) && ($_GET['action'] == 'admin' || $_GET['action'] == 'closeevent' || $_GET['action'] == 'openevent' || $_GET['action'] == 'deleteevent' || $_GET['action'] == 'editevent' || $_GET['action'] == 'updateseeding' || $_GET['action'] == 'ban' || $_GET['action'] == 'unban' || $_GET['action'] == 'admingroups')) {
	$queryResult = $mysqli->query('SELECT *,(SELECT COUNT(*) FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.') AS numTeams FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent.';');
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_assoc();
		echo "\t\t\t<h1>Current Event Information</h1>\n";
		echo "\t\t\t<table>\n";
		echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Event Name:</b></td><td class=\"leftAlign\">".$resultArray['description']."</td></tr>\n";
		echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Maximum Teams:</b></td><td class=\"leftAlign\">".$resultArray['maxTeams']."</td></tr>\n";
		echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Minimum Team Size:</b></td><td class=\"leftAlign\">".$resultArray['minTeamSize']."</td></tr>\n";
		echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Maximum Team Size:</b></td><td class=\"leftAlign\">".$resultArray['maxTeamSize']."</td></tr>\n";
		echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Start Date/Time:</b></td><td class=\"leftAlign\">".date("D, M d Y, H:i", strtotime($resultArray['startTime']))." GMT</td></tr>\n";
		echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Registration Buffer:</b></td><td class=\"leftAlign\">".$resultArray['registrationBuffer']." minutes</td></tr>\n";
		echo "\t\t\t\t<tr><td class=\"rightAlign\"><b>Member Groups:</b></td><td class=\"leftAlign\">".$resultArray['memberGroups']."</td></tr>\n";
		echo "\t\t\t</table>\n";
		echo "\t\t\t<h1>Event Manipulations</h1>\n";
		echo "\t\t\t<form action=\".\" method=\"GET\"><p class=\"compact\"><input type=\"hidden\" name=\"action\" value=\"prompteditevent\"><input type=\"submit\" value=\"Edit Event Information\" class=\"submitButton\"></p></form>\n";
		$queryResult = $mysqli->query('SELECT COUNT(*) FROM '.$mySQLPrefix.'results WHERE event='.$currentEvent);
		if($queryResult && $queryResult->num_rows > 0) {
			$resultArray = $queryResult->fetch_row();
			if($resultArray[0] == 0) {
				if($eventClosed)
					echo "\t\t\t\t<form action=\".\" method=\"GET\"><p class=\"compact\"><input type=\"hidden\" name=\"action\" value=\"openevent\"><input type=\"submit\" value=\"Open Registration\" class=\"submitButton\"></p></form>\n";
				else
					echo "\t\t\t<form action=\".\" method=\"GET\"><p class=\"compact\"><input type=\"hidden\" name=\"action\" value=\"closeevent\"><input type=\"submit\" value=\"Close Registration\" class=\"submitButton\"></p></form>\n";
				echo "\t\t\t<form action=\".\" method=\"GET\"><p class=\"compact\"><input type=\"hidden\" name=\"action\" value=\"updateseeding\"><input type=\"submit\" value=\"Update Seeding\" class=\"submitButton\"></p></form>\n";
			}
		}
		echo "\t\t\t<form action=\".\" method=\"GET\"><p class=\"compact\"><input type=\"hidden\" name=\"action\" value=\"promptdeleteevent\"><input type=\"submit\" value=\"Delete Event\" class=\"submitButton\"></p></form>\n";
		echo "\t\t\t<form action=\".\" method=\"GET\"><p><input type=\"hidden\" name=\"action\" value=\"promptcreateevent\"><input type=\"submit\" value=\"Create New Event\" class=\"submitButton\"></p></form>\n";
	} else {
		echo "\t\t\t<h1>Event Manipulations</h1>\n";
		echo "\t\t\t<form action=\".\" method=\"GET\"><p><input type=\"hidden\" name=\"action\" value=\"promptcreateevent\"><input type=\"submit\" value=\"Create New Event\" class=\"submitButton\"></p></form>\n";
	}
	echo "\t\t\t<h1>Other Administrative Tasks</h1>\n";
	$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'users WHERE banned=FALSE ORDER BY callsign');
	if($queryResult && $queryResult->num_rows > 0) {
		echo "\t\t\t<form action=\".?action=spoof\" method=\"POST\">\n";
		echo "\t\t\t\t<fieldset>\n";
		echo "\t\t\t\t\t<legend>Spoof Player</legend>\n";
		echo "\t\t\t\t\t<p class=\"tight\">\n";
		echo "\t\t\t\t\t\tCallsign:\n";
		echo "\t\t\t\t\t\t<select name=\"bzid\">\n";
		echo "\t\t\t\t\t\t\t<option value=\"\" selected></option>\n";
		$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
		foreach($resultArray as $result)
			echo "\t\t\t\t\t\t\t<option value=\"".$result['bzid']."\">".$result['callsign']."</option>\n";
		echo "\t\t\t\t\t\t</select> <i>(does not account for group membership or administrator status)</i>\n";
		echo "\t\t\t\t\t</p>\n";
		echo "\t\t\t\t\t<br>\n";
		echo "\t\t\t\t\t<p class=\"tight\"><input type=\"submit\" value=\"Spoof\" class=\"submitButton\"></p>\n";
		echo "\t\t\t\t</fieldset>\n";
		echo "\t\t\t</form>\n";
		echo "\t\t\t<br>\n";
	}
	echo "\t\t\t<form action=\".?action=ban\" method=\"POST\">\n";
	echo "\t\t\t\t<fieldset>\n";
	echo "\t\t\t\t\t<legend>Ban Player</legend>\n";
	$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'users WHERE banned=FALSE ORDER BY callsign');
	if($queryResult && $queryResult->num_rows > 0) {
		echo "\t\t\t\t\t<p class=\"tight\">\n";
		echo "\t\t\t\t\t\tCallsign:\n";
		echo "\t\t\t\t\t\t<select name=\"bzid\">\n";
		echo "\t\t\t\t\t\t\t<option value=\"\" selected></option>\n";
		$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
		foreach($resultArray as $result)
			echo "\t\t\t\t\t\t\t<option value=\"".$result['bzid']."\">".$result['callsign']."</option>\n";
		echo "\t\t\t\t\t\t</select>\n";
		echo "\t\t\t\t\t</p>\n";
		echo "\t\t\t\t\t<br>\n";
		echo "\t\t\t\t\t<p class=\"tight\"><input type=\"submit\" value=\"Ban\" class=\"submitButton\"></p>\n";
	} else {
		echo "\t\t\t\t\t<p><i>There are currently no unbanned players.</p></i>\n";
	}
	echo "\t\t\t\t</fieldset>\n";
	echo "\t\t\t</form>\n";
	echo "\t\t\t<br>\n";
	echo "\t\t\t<form action=\".?action=unban\" method=\"POST\">\n";
	echo "\t\t\t\t<fieldset>\n";
	echo "\t\t\t\t\t<legend>Unban Player</legend>\n";
	$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'users WHERE banned=TRUE ORDER BY callsign');
	if($queryResult && $queryResult->num_rows > 0) {
		echo "\t\t\t\t\t<p class=\"tight\">\n";
		echo "\t\t\t\t\t\tCallsign:\n";
		echo "\t\t\t\t\t\t<select name=\"bzid\">\n";
		echo "\t\t\t\t\t\t\t<option value=\"\" selected></option>\n";
		$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
		foreach($resultArray as $result)
			echo "\t\t\t\t\t\t\t<option value=\"".$result['bzid']."\">".$result['callsign']."</option>\n";
		echo "\t\t\t\t\t\t</select>\n";
		echo "\t\t\t\t\t</p>\n";
		echo "\t\t\t\t\t<br>\n";
		echo "\t\t\t\t\t<p class=\"tight\"><input type=\"submit\" value=\"Unban\" class=\"submitButton\"></p>\n";
	} else {
		echo "\t\t\t\t\t<p><i>There are currently no banned players.</i></p>\n";
	}
	echo "\t\t\t\t</fieldset>\n";
	echo "\t\t\t</form>\n";
	echo "\t\t\t<br>\n";
	$groups = '';
	$queryResult = $mysqli->query('SELECT adminGroups FROM '.$mySQLPrefix.'config');
	if($queryResult && $queryResult->num_rows > 0) {
		$resultArray = $queryResult->fetch_assoc();
		$groups = $resultArray['adminGroups'];
	}
	echo "\t\t\t<form action=\".?action=admingroups\" method=\"POST\">\n";
	echo "\t\t\t\t<fieldset>\n";
	echo "\t\t\t\t\t<legend>Update Administrative Group(s)</legend>\n";
	echo "\t\t\t\t\t<p class=\"tight\">Group(s): <input name=\"groups\" type=\"text\" value=\"".$groups."\" size=\"50\"> <i>(separate group names with spaces or commas)</i></p>\n";
	echo "\t\t\t\t\t<br>\n";
	echo "\t\t\t\t\t<p class=\"tight\"><input type=\"submit\" value=\"Update\" class=\"submitButton\"></p>\n";
	echo "\t\t\t\t</fieldset>\n";
	echo "\t\t\t</form>\n";
} else if($isAdmin && $configUp && array_key_exists('action', $_GET) && ($_GET['action'] == 'promptcreateevent' || $_GET['action'] == 'prompteditevent')) {
	$registrationsExist = FALSE;
	if($_GET['action'] == 'prompteditevent') {
		$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent);
		if($queryResult && $queryResult->num_rows > 0)
			$registrationsExist = TRUE;
	}
	$resultsExist = FALSE;
	if($_GET['action'] == 'prompteditevent') {
		$queryResult = $mysqli->query('SELECT matchNumber FROM '.$mySQLPrefix.'results WHERE event='.$currentEvent);
		if($queryResult && $queryResult->num_rows > 0)
			$resultsExist = TRUE;
	}

	echo "\t\t\t<h1>".($_GET['action'] == "promptcreateevent" ? "Create Event" : "Edit Event")."</h1>\n";
	echo "\t\t\t<form action=\".?action=editevent\" method=\"POST\">\n";
	echo "\t\t\t\t<p class=\"tight\"><input type=\"hidden\" name=\"existing\" value=\"".($_GET['action'] == "promptcreateevent" ? "0" : "1")."\"></p>\n";
	echo "\t\t\t\t<table>\n";
	$resultArray = Array('description' => '', 'maxTeams' => 16, 'minTeamSize' => 2, 'maxTeamSize' => 3, 'startTime' => date("Y-m-d H:i:s"), 'registrationBuffer' => 2880, 'memberGroups' => 'LU.PLAYER');
	if($_GET['action'] == "prompteditevent") {
		$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent);
		if($queryResult && $queryResult->num_rows > 0)
			$resultArray = $queryResult->fetch_assoc();
	}
	$dateElements = Array();
	preg_match('/^(\d+)-(\d+)-(\d+)\s(\d+):(\d+):\d+$/', $resultArray['startTime'], $dateElements);
	echo "\t\t\t\t\t<tr><td class=\"rightAlign\"><b>Event Name:</b></td><td class=\"leftAlign\"><input type=\"text\" name=\"description\" value=\"".$resultArray['description']."\" size=\"50\"></td></tr>\n";
	echo "\t\t\t\t\t<tr><td class=\"rightAlign\"><b>Maximum Teams:</b></td><td class=\"leftAlign\"><input type=\"text\" name=\"maxTeams\" value=\"".$resultArray['maxTeams']."\" size=\"4\" maxlength=\"4\"".($resultsExist ? " readonly" : "")."></td></tr>\n";
	echo "\t\t\t\t\t<tr><td class=\"rightAlign\"><b>Minimum Team Size:</b></td><td class=\"leftAlign\"><input type=\"text\" name=\"minTeamSize\" value=\"".$resultArray['minTeamSize']."\" size=\"3\" maxlength=\"3\"".($registrationsExist ? " readonly" : "")."></td></tr>\n";
	echo "\t\t\t\t\t<tr><td class=\"rightAlign\"><b>Maximum Team Size:</b></td><td class=\"leftAlign\"><input type=\"text\" name=\"maxTeamSize\" value=\"".$resultArray['maxTeamSize']."\" size=\"3\" maxlength=\"3\"".($registrationsExist ? " readonly" : "")."></td></tr>\n";
	echo "\t\t\t\t\t<tr><td class=\"rightAlign\"><b>Start Date/Time:</b></td><td class=\"leftAlign\"><input type=\"text\" name=\"month\" value=\"".$dateElements[2]."\" size=\"2\" maxlength=\"2\"><input type=\"text\" name=\"day\" value=\"".$dateElements[3]."\" size=\"2\" maxlength=\"2\"><input type=\"text\" name=\"year\" value=\"".$dateElements[1]."\" size=\"4\" maxlength=\"4\">&nbsp;<input type=\"text\" name=\"hour\" value=\"".$dateElements[4]."\" size=\"2\" maxlength=\"2\"><input type=\"text\" name=\"minute\" value=\"".$dateElements[5]."\" size=\"2\" maxlength=\"2\"> GMT <i>(MM DD YYYY HH MM)</i></td></tr>\n";
	echo "\t\t\t\t\t<tr><td class=\"rightAlign\"><b>Registration Buffer:</b></td><td class=\"leftAlign\"><input type=\"text\" name=\"registrationBuffer\" value=\"".$resultArray['registrationBuffer']."\" size=\"6\" maxlength=\"6\"> <i>(minutes)</i></td></tr>\n";
	echo "\t\t\t\t\t<tr><td class=\"rightAlign\"><b>Member Groups:</b></td><td class=\"leftAlign\"><input type=\"text\" name=\"memberGroups\" value=\"".$resultArray['memberGroups']."\" size=\"50\"> <i>(separate group names with spaces or commas)</i></td></tr>\n";
	echo "\t\t\t\t\t<tr><td class=\"rightAlign\"><input type=\"submit\" value=\"Submit\" class=\"submitButton\"></td><td>&nbsp;</td></tr>\n";
	echo "\t\t\t\t</table>\n";
	echo "\t\t\t</form>\n";
} else if ($isAdmin && $configUp && array_key_exists('action', $_GET) && $_GET['action'] == 'promptdeleteevent') {
	echo "\t\t\t<h1>Delete Event</h1>\n";
	echo "\t\t\t<p>Please confirm whether you wish to delete the current event:</p>\n";
	echo "\t\t\t<table>\n";
	echo "\t\t\t\t<tr>\n";
	echo "\t\t\t\t\t<td><form action=\".\" method=\"GET\"><p class=\"tight\"><input type=\"hidden\" name=\"action\" value=\"admin\"><input type=\"submit\" value=\"Cancel\" class=\"submitButton\"></p></form></td>\n";
	echo "\t\t\t\t\t<td><form action=\".\" method=\"GET\"><p class=\"tight\"><input type=\"hidden\" name=\"action\" value=\"deleteevent\"><input type=\"submit\" value=\"Confirm\" class=\"submitButton\"></p></form></td>\n";
	echo "\t\t\t\t</tr>\n";
	echo "\t\t\t</table>\n";
} else if ($isAdmin && $currentEvent && array_key_exists('action', $_GET) && $_GET['action'] == 'promptenterresult') {
	echo "\t\t\t<h1>".(isset($_POST['team1Score']) && isset($_POST['team2Score']) ? 'Edit' : 'Enter')." Match Result</h1>\n";
	echo "\t\t\t<form action=\".?action=enterresult\" method=\"POST\">\n";
	echo "\t\t\t\t<p class=\"tight\"><input type=\"hidden\" name=\"match\" value=\"".$_POST['match']."\"></p>\n";
	echo "\t\t\t\t<table>\n";
	echo "\t\t\t\t\t<tr><th>Team</th><th>Score</th></tr>\n";
	echo "\t\t\t\t\t<tr><td class=\"leftAlign\">".$_POST['team1']."</td><td><input name=\"team1Score\" type=\"text\" value=\"".(isset($_POST['team1Score']) ? $_POST['team1Score'] : '')."\" size=\"2\"></td></tr>\n";
	echo "\t\t\t\t\t<tr><td class=\"leftAlign\">".$_POST['team2']."</td><td><input name=\"team2Score\" type=\"text\" value=\"".(isset($_POST['team2Score']) ? $_POST['team2Score'] : '')."\" size=\"2\"></td></tr>\n";
	echo "\t\t\t\t</table>\n";
	echo "\t\t\t\t<p>Disqualify:&nbsp;\n";
	echo "\t\t\t\t\t<select name=\"disqualifyTeam\">\n";
	echo "\t\t\t\t\t\t<option value=\"0\"".(isset($_POST['disqualifyTeam']) && $_POST['disqualifyTeam'] == 0 ? " selected" : "")."></option>\n";
	echo "\t\t\t\t\t\t<option value=\"1\"".(isset($_POST['disqualifyTeam']) && $_POST['disqualifyTeam'] == 1 ? " selected" : "").">".$_POST['team1']."</option>\n";
	echo "\t\t\t\t\t\t<option value=\"2\"".(isset($_POST['disqualifyTeam']) && $_POST['disqualifyTeam'] == 2 ? " selected" : "").">".$_POST['team2']."</option>\n";
	echo "\t\t\t\t\t</select>\n";
	echo "\t\t\t\t</p>\n";
	echo "\t\t\t\t<p><input type=\"submit\" value=\"Enter\" class=\"submitButton\"></p>\n";
	echo "\t\t\t</form>\n";
	if(isset($_POST['team1Score']) && $_POST['team1Score'] != '' && isset($_POST['team2Score']) && $_POST['team2Score'] != '') echo "\t\t\t<form action=\".?action=deleteresult\" method=\"POST\"><p><input type=\"hidden\" name=\"match\" value=\"".$_POST['match']."\"><input type=\"submit\" value=\"Delete Result\" class=\"submitButton\"></p></form>\n";
	echo "\t\t\t<form action=\".\" method=\"GET\"><p><input type=\"submit\" value=\"Cancel\" class=\"submitButton\"></p></form>\n";
} else if($configUp) {
	if(! isset($_GET['event']))
		echo "\t\t\t<p>The BZFlag FunMatch Challenge is a single-elimination multi-player funmatch tournament for BZFlag league players. This event consists of a series of twenty-minute matches (with a thirty-minute final match) which take place on a single day over several hours (depending on the turnout). Teams may consist of any combination of league members. For further information, please refer to the <a href=\"?action=info\">information</a> page. To register for the tournament, or to modify or cancel an existing registration, please ".(array_key_exists('bzid', $_SESSION) ? "visit the <a href=\"?action=registration\">registration</a>" : "<a href=\"".$loginURL."\">log in</a> and visit the registration")." page.</p>\n";
	if($currentEvent) {
		if(isset($_GET['event']) && is_numeric($_GET['event']) && $currentEvent != $_GET['event']) {
			$queryResult = $mysqli->query('SELECT description FROM '.$mySQLPrefix.'events WHERE id='.$_GET['event'].' AND isClosed=TRUE');
			if($queryResult && $queryResult->num_rows > 0) {
				$resultArray = $queryResult->fetch_row();
				$currentEvent = $_GET['event'];
				$eventClosed = TRUE;
			}
		}
		$queryResult = $mysqli->query('SELECT description,startTime,registrationBuffer,minTeamSize,maxTeamSize FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent);
		if($queryResult && $queryResult->num_rows > 0) {
			$resultArray = $queryResult->fetch_assoc();
			echo "\t\t\t<h1>Event Details</h1>\n";
			echo "\t\t\t<p>\n";
			echo "\t\t\t\t<b>Event Name:</b> ".$resultArray['description']."<br>\n";
			if(! isset($_GET['event'])) echo "\t\t\t\t<b>Registration Close: </b>".date("l, F j, Y, G:i", strtotime($resultArray['startTime']) - $resultArray['registrationBuffer'] * 60)." GMT<br>\n";
			echo "\t\t\t\t<b>Scheduled Start: </b>".date("l, F j, Y, G:i", strtotime($resultArray['startTime']))." GMT<br>\n";
			if(! isset($_GET['event'])) echo "\t\t\t\t<b>Home Server:</b> <i>(see server list)</i><br>\n";
			echo "\t\t\t\t<b>Team Size:</b> ".$resultArray['minTeamSize']." - ".$resultArray['maxTeamSize']."\n";
			echo "\t\t\t</p>\n";
			if($eventClosed) {
				$queryResult = $mysqli->query('SELECT team AS teamID,(SELECT sufficiencyTime FROM '.$mySQLPrefix.'teams WHERE id=teamID) as sufficiencyTime, GROUP_CONCAT((SELECT callsign FROM '.$mySQLPrefix.'users WHERE bzid='.$mySQLPrefix.'memberships.bzid) SEPARATOR ", ") AS members,((SELECT COUNT(*) FROM '.$mySQLPrefix.'memberships WHERE rating IS NOT NULL AND team=teamID) >= (SELECT minTeamSize FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent.')) AS enough,(SELECT maxTeams FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent.') AS maxTeams FROM '.$mySQLPrefix.'memberships WHERE '.$mySQLPrefix.'memberships.rating IS NOT NULL AND team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.') GROUP BY team ORDER BY FLOOR(SUM(rating) / COUNT(rating)) DESC,sufficiencyTime');
				if($queryResult && $queryResult->num_rows > 0) {
					$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
					$rank = 1;
					$dateSorted = Array();
					foreach($resultArray as $result) {
						if($result['enough']) {
							$result['rank'] = $rank++;
							$realTime = $result['sufficiencyTime'] == '' ? 0 : strtotime($result['sufficiencyTime']);
							if(! array_key_exists($realTime, $dateSorted))
								$dateSorted[$realTime] = Array();
							array_push($dateSorted[$realTime], $result);
						}
					}
					ksort($dateSorted);
					$teamOrder = Array();
					$teamCount = 0;
					foreach($dateSorted as $dateEntry) {
						foreach($dateEntry as $team) {
							if($teamCount < $resultArray[0]['maxTeams'])
								$teamOrder[$team['rank']] = $team['members'];
							++$teamCount;
						}
					}
					ksort($teamOrder);
					$rank = 1;
					$teams = Array();
					foreach($teamOrder as $team)
						$teams[$rank++] = $team;
					$numRounds = ceil(log(count($teams), 2));
					$numMatches = count($teams) - 1;
					$matches = array_fill(1, $numMatches, Array('favorite' => 0, 'favoriteScore' => '', 'challenger' => 0, 'challengerScore' => '', 'disqualifyTeam' => 0, 'victor' => 0));
					$currentRound = $numRounds;
					$currentIndex = $numMatches;
					while($currentRound > 1) {
						if(pow(2, $currentRound) + $currentIndex > $numMatches)
							$matches[$currentIndex]['favorite'] = pow(2, $currentRound) - $currentIndex;
						else
							$matches[$currentIndex]['favorite'] =& $matches[pow(2, $currentRound) + $currentIndex]['victor'];
						if(pow(2, $currentRound + 1) - $currentIndex - 1 > $numMatches)
							$matches[$currentIndex]['challenger'] = $currentIndex + 1;
						else
							$matches[$currentIndex]['challenger'] =& $matches[pow(2, $currentRound + 1) - $currentIndex - 1]['victor'];
						--$currentIndex;
						if(pow(2, $currentRound - 1) > $currentIndex)
							--$currentRound;
					}
					if($numMatches >= 3) {
						$matches[1]['favorite'] =& $matches[3]['victor'];
						$matches[1]['challenger'] =& $matches[2]['victor'];
					} else if ($numMatches == 2) {
						$matches[1]['favorite'] = 1;
						$matches[1]['challenger'] =& $matches[2]['victor'];
					} else {
						$matches[1]['favorite'] = 1;
						$matches[1]['challenger'] = 2;
					}
					$queryResult = $mysqli->query('SELECT * FROM '.$mySQLPrefix.'results WHERE event='.$currentEvent.' ORDER BY matchNumber DESC');
					if($queryResult && $queryResult->num_rows > 0) {
						$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
						for($i = 0; $i < count($resultArray); ++$i) {
							$matches[$resultArray[$i]['matchNumber']]['favoriteScore'] = $resultArray[$i]['team1Score'];
							$matches[$resultArray[$i]['matchNumber']]['challengerScore'] = $resultArray[$i]['team2Score'];
							$higherRankedChallenger = ($matches[$resultArray[$i]['matchNumber']]['favorite'] > $matches[$resultArray[$i]['matchNumber']]['challenger'] ? TRUE : FALSE);
							if($resultArray[$i]['disqualifyTeam'] != 0)
								$matches[$resultArray[$i]['matchNumber']]['victor'] = ($resultArray[$i]['disqualifyTeam'] == 1 ? $matches[$resultArray[$i]['matchNumber']]['challenger'] : $matches[$resultArray[$i]['matchNumber']]['favorite']);
							else if($resultArray[$i]['team1Score'] == $resultArray[$i]['team2Score'] && ! $higherRankedChallenger)
								$matches[$resultArray[$i]['matchNumber']]['victor'] = $matches[$resultArray[$i]['matchNumber']]['challenger'];
							else if($resultArray[$i]['team1Score'] < $resultArray[$i]['team2Score'])
								$matches[$resultArray[$i]['matchNumber']]['victor'] = $matches[$resultArray[$i]['matchNumber']]['challenger'];
							else
								$matches[$resultArray[$i]['matchNumber']]['victor'] = $matches[$resultArray[$i]['matchNumber']]['favorite'];
							$matches[$resultArray[$i]['matchNumber']]['disqualifyTeam'] = $resultArray[$i]['disqualifyTeam'];
						}
					}
					$rankOrder = Array(1);
					for($round = 1; $round <= $numRounds; ++$round) {
						$insertAfter = TRUE;
						for($rank = pow(2, $round); $rank > pow(2, $round - 1); --$rank) {
							$target = pow(2, $round) - $rank + 1;
							$index = 0;
							while($index < count($rankOrder) && $target != $rankOrder[$index]) {
								++$index;
							}
							$firstPart = array_splice($rankOrder, 0, $index + ($insertAfter ? 1 : 0));
							$rankOrder = array_merge($firstPart, Array($rank), $rankOrder);
							$insertAfter = ! $insertAfter;
						}
					}
					$gridSpaces = Array();
					for($i = 0; $i <= $numRounds; ++$i)
						$gridSpaces[$i] = array_fill(0, count($rankOrder) * 2 - 1, Array('content' => '', 'cellType' => 'none', 'score' => ''));
					$currentRoundRankOrder = $rankOrder;
					for($match = count($matches); $match >= 1; --$match) {
						$round = ceil(log($match + 1, 2));
						if(count($currentRoundRankOrder) > pow(2, $round)) {
							$selectAfter = FALSE;
							$newRankOrder = Array();
							for($i = 0; $i < pow(2, $round); ++$i) {
								array_push($newRankOrder, $currentRoundRankOrder[$i * 2 + ($selectAfter ? 1 : 0)]);
								$selectAfter = ! $selectAfter;
							}
							$currentRoundRankOrder = $newRankOrder;
						}
						$favoritePosition = pow(2, $round) - $match;
						$challengerPosition = $match + 1;
						$favoriteIndex = 0;
						for($i = 0; $i < count($currentRoundRankOrder); ++$i) {
							if($currentRoundRankOrder[$i] == $favoritePosition) {
								$favoriteIndex = $i * pow(2, $numRounds - $round + 1) + pow(2, $numRounds - $round) - 1;
								break;
							}
						}
						$challengerIndex = 0;
						for($i = 0; $i < count($currentRoundRankOrder); ++$i) {
							if($currentRoundRankOrder[$i] == $challengerPosition) {
								$challengerIndex = $i * pow(2, $numRounds - $round + 1) + pow(2, $numRounds - $round) - 1;
								break;
							}
						}
						$gridSpaces[$numRounds - $round][$favoriteIndex]['content'] = $matches[$match]['favorite'].($matches[$match]['disqualifyTeam'] == 1 ? ' -' : '');
						$gridSpaces[$numRounds - $round][$challengerIndex]['content'] = $matches[$match]['challenger'].($matches[$match]['disqualifyTeam'] == 2 ? ' -' : '');
						$gridSpaces[$numRounds - $round][$favoriteIndex]['score'] = $matches[$match]['favoriteScore'];
						$gridSpaces[$numRounds - $round][$challengerIndex]['score'] = $matches[$match]['challengerScore'];
						if($gridSpaces[$numRounds - $round][$favoriteIndex]['cellType'] == 'wall')
							$gridSpaces[$numRounds - $round][$favoriteIndex]['cellType'] = 'branch';
						else
							$gridSpaces[$numRounds - $round][$favoriteIndex]['cellType'] = 'base';
						if($gridSpaces[$numRounds - $round][$challengerIndex]['cellType'] == 'wall')
							$gridSpaces[$numRounds - $round][$challengerIndex]['cellType'] = 'branch';
						else
							$gridSpaces[$numRounds - $round][$challengerIndex]['cellType'] = 'base';
						$lowerCell = ($favoriteIndex < $challengerIndex ? $favoriteIndex : $challengerIndex);
						$numSpaces = abs($favoriteIndex - $challengerIndex);
						for($i = $lowerCell + 1; $i <= $lowerCell + $numSpaces; ++$i)
							$gridSpaces[$numRounds - $round + 1][$i]['cellType'] = 'wall';
						if($matches[$match]['favorite'] != 0 && $matches[$match]['challenger'] != 0 && ($match == 1 || $matches[getDependentMatchNumber($match)]['victor'] == 0))
							$gridSpaces[$numRounds - $round][$lowerCell + $numSpaces / 2]['content'] = ($matches[$match]['favoriteScore'] != '' && $matches[$match]['challengerScore'] != '' ? 'Edit ' : 'Enter ').$match;
					}
					$gridSpaces[$numRounds][count($rankOrder) - 1]['content'] = $matches[1]['victor'];
					$gridSpaces[$numRounds][count($rankOrder) - 1]['cellType'] = 'branch';
					if($numRounds == 0) {
						echo "\t\t\t<h1>Results</h1>\n";
						if(! isset($_GET['event']))
							echo "\t\t\t<p>This event has concluded with only one team participating.</p>\n";
						$queryResult = $mysqli->query('SELECT GROUP_CONCAT(callsign SEPARATOR ", ") FROM '.$mySQLPrefix.'users WHERE bzid IN (SELECT bzid FROM '.$mySQLPrefix.'memberships WHERE team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.'))');
						if($queryResult && $queryResult->num_rows > 0) {
							$resultArray = $queryResult->fetch_row();
							echo "\t\t\t<table>\n";
							echo "\t\t\t\t<tr><th>Place</th><th>Team&nbsp;Members</th></tr>\n";
							echo "\t\t\t\t<tr><td>1</td><td>".$resultArray[0]."</td></tr>\n";
							echo "\t\t\t</table>\n";
						}
					} else if($matches[1]['victor'] != 0 ) {
						echo "\t\t\t<h1>Results</h1>\n";
						if(! isset($_GET['event']))
							echo "\t\t\t<p>This event has concluded. The final results are posted below. Congratulations to the winning team, and thanks to all for participating!</p>\n";
						$results = array_fill(0, $numRounds + 1, Array());
						array_push($results[0], $matches[1]['victor']);
						$disqualifiedTeams = Array();
						for($match = count($matches); $match >= 1; --$match) {
							if($matches[$match]['disqualifyTeam'] == 0)
								array_push($results[ceil(log($match + 1, 2))], $matches[$match][($matches[$match]['favorite'] == $matches[$match]['victor'] ? 'challenger' : 'favorite')]);
							else
								array_push($disqualifiedTeams, $matches[$match][($matches[$match]['favorite'] == $matches[$match]['victor'] ? 'challenger' : 'favorite')]);
						}
						for($round = 0; $round <= $numRounds; ++$round)
							sort($results[$round]);
						echo "\t\t\t<table>\n";
						echo "\t\t\t\t<tr><th>Place</th><th>Team&nbsp;Members</th></tr>\n";
						$altRow = FALSE;
						$placeCount = 1;
						for($round = 0; $round <= $numRounds; ++$round) {
							for($match = 0; $match < count($results[$round]); ++$match) {
								echo "\t\t\t\t<tr".($altRow ? ' class="altRow"' : '')."><td>".$placeCount++."</td><td class=\"leftAlign\">".$teams[$results[$round][$match]]."</td></tr>\n";
								$altRow = ! $altRow;
							}
						}
						for($i = count($disqualifiedTeams) - 1; $i >= 0; --$i) {
							echo "\t\t\t\t<tr".($altRow ? ' class="altRow"' : '')."><td><i>DNF</i></td><td class=\"leftAlign\">".$teams[$disqualifiedTeams[$i]]."</td></tr>\n";
							$altRow = ! $altRow;
						}
						echo "\t\t\t</table>\n";
					}
					echo "\t\t\t<h1>Elimination Bracket</h1>\n";
					if(! isset($_GET['event']))
						echo "\t\t\t<p>The elimination bracket shows the progression of matches until all but one team is eliminated. Please note the scheduled time and assigned opponent of your first match. Further match assignments will be announced on the tournament home server after the earlier matches have been reported. This table will be updated throughout the tournament as matches are completed.</p>\n";
					$startTime = 0;
					$queryResult = $mysqli->query('SELECT startTime FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent);
					if($queryResult && $queryResult->num_rows > 0) {
						$resultArray = $queryResult->fetch_assoc();
						$startTime = strtotime($resultArray['startTime']);
					}
					if($numRounds == 0) {
						$queryResult = $mysqli->query('SELECT GROUP_CONCAT(callsign SEPARATOR ", ") FROM '.$mySQLPrefix.'users WHERE bzid IN (SELECT bzid FROM '.$mySQLPrefix.'memberships WHERE team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.'))');
						if($queryResult && $queryResult->num_rows > 0) {
							$resultArray = $queryResult->fetch_row();
							echo "\t\t\t<table class=\"bracket\">\n";
							echo "\t\t\t\t<tr><th colspan=\"3\">Winner</th></tr>\n";
							echo "\t\t\t\t<tr>\n";
							echo "\t\t\t\t\t<td class=\"base\">&nbsp;</td>\n";
							echo "\t\t\t\t\t<td class=\"base\">".$resultArray[0]."</td>\n";
							echo "\t\t\t\t\t<td class=\"base\">&nbsp;</td>\n";
							echo "\t\t\t\t</tr>\n";
							echo "\t\t\t</table>";
						}
					} else {
						$numBrackets = 1; // we're back to only displaying one bracket, instead of breaking it up into multiple narrower brackets
						$brackets = Array();
						for($bracket = 1; $bracket <= $numBrackets; ++$bracket)
							$brackets[$bracket] = Array();
						for($column = 1; $column <= $numRounds + 1; ++$column) {
							if($column > 1 && count($brackets[1]) == 0)
								array_push($brackets[1], $column - 1);
							array_push($brackets[1], $column);
						}
						foreach($brackets as $bracket => $columns) {
							echo "\t\t\t<table class=\"bracket\">\n";
							echo "\t\t\t\t<tr>\n";
							echo "\t\t\t\t\t";
							for($i = 0 ; $i < count($columns) - 1; ++$i)
								echo "<th colspan=\"3\">".date("G:i", $startTime + ($columns[0] + $i - 1) * 1800)." Start</th>";
							echo "<th colspan=\"3\">".($columns[count($columns) - 1] - 1 == $numRounds ? "Winner" : "&nbsp;")."</th>\n";
							echo "\t\t\t\t</tr>\n";
							echo "\t\t\t\t<tr><td colspan=\"".(count($columns) * 3)."\">&nbsp;</td></tr>\n";
							for($row = pow(2, $columns[0] - 1) - 1; $row < count($rankOrder) * 2 - 1; $row += pow(2, $columns[0] - 1)) {
								$rowIsBlank = TRUE;
								foreach($columns as $columnIndex => $column) {
									if($gridSpaces[$column - 1][$row]['cellType'] != 'none') {
										$rowIsBlank = FALSE;
										break;
									}
								}
								if($rowIsBlank)
									continue;
								echo "\t\t\t\t<tr>\n";
								foreach($columns as $columnIndex => $column) {
									$gridSpace =& $gridSpaces[$column - 1][$row];
									if($gridSpace['cellType'] == 'none' || ($columnIndex == 0 && $gridSpace['cellType'] == 'wall'))
										echo "\t\t\t\t\t<td>";
									else if($columnIndex == 0 && $gridSpace['cellType'] == 'branch')
										echo "\t\t\t\t\t<td class=\"base\">";
									else
										echo "\t\t\t\t\t<td class=\"".$gridSpace['cellType']."\">";
									if(preg_match('/^\d+\s-$/', $gridSpace['content']))
										echo preg_replace('/^(\d+)\s-$/', "$1", $gridSpace['content'])."</td>\n";
									else if(is_numeric($gridSpace['content']) && $gridSpace['content'] != 0)
										echo $gridSpace['content']."</td>\n";
									else
										echo "&nbsp;</td>\n";
									$pregMatches = Array();
									if(preg_match('/^(\d+)\s?(-)?$/', $gridSpace['content'], $pregMatches)) {
										echo "\t\t\t\t\t<td class=\"".($gridSpace['cellType'] == 'base' || $gridSpace['cellType'] == 'branch' ? 'base ' : '').(count($pregMatches) > 2 && $columnIndex < count($columns) - 1 ? "strikethrough " : "")."leftAlign\">".($pregMatches[1] > 0 ? preg_replace("/\s/", "&nbsp;", $teams[$pregMatches[1]]) : '')."</td>\n";
										echo "\t\t\t\t\t<td".($gridSpace['cellType'] == 'base' || $gridSpace['cellType'] == 'branch' ? " class=\"base\"" : '').">".($columnIndex < count($columns) - 1 ? "<b>".$gridSpace['score']."</b>" : "&nbsp;")."</td>\n";
									} else {
										if($gridSpace['content'] == '') {
											echo "\t\t\t\t\t<td".($gridSpace['cellType'] == 'base' || $gridSpace['cellType'] == 'branch' ? " class=\"base\"" : '').">";
											for($i = 0; $i < 40; ++$i) echo "&nbsp;";
											echo "</td>\n";
										} else {
											$pregMatches = Array();
											if($isAdmin && ! isset($_GET['event']) && preg_match("/^(Enter|Edit)\s(\d+)$/", $gridSpace['content'], $pregMatches) != FALSE && $columnIndex < count($columns) - 1) {
												echo "\t\t\t\t\t<td class=\"button\">\n";
												echo "\t\t\t\t\t\t<form action=\".?action=promptenterresult\" method=\"POST\">\n";
												echo "\t\t\t\t\t\t\t<p class=\"tight\">\n";
												echo "\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"match\" value=\"".$pregMatches[2]."\">\n";
												echo "\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"team1\" value=\"".$teams[$matches[$pregMatches[2]]['favorite']]."\">\n";
												echo "\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"team1Score\" value=\"".$matches[$pregMatches[2]]['favoriteScore']."\">\n";
												echo "\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"team2\" value=\"".$teams[$matches[$pregMatches[2]]['challenger']]."\">\n";
												echo "\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"team2Score\" value=\"".$matches[$pregMatches[2]]['challengerScore']."\">\n";
												echo "\t\t\t\t\t\t\t\t<input type=\"hidden\" name=\"disqualifyTeam\" value=\"".$matches[$pregMatches[2]]['disqualifyTeam']."\">\n";
												echo "\t\t\t\t\t\t\t\t<input type=\"submit\" value=\"".$pregMatches[1]." Result\" class=\"smallSubmitButton\">\n";
												echo "\t\t\t\t\t\t\t</p>\n";
												echo "\t\t\t\t\t\t</form>\n";
												echo "\t\t\t\t\t</td>\n";
											} else {
												echo "\t\t\t\t\t<td".($gridSpace['cellType'] == 'base' || $gridSpace['cellType'] == 'branch' ? " class=\"base\"" : '').">&nbsp;</td>\n";
											}
										}
										echo "\t\t\t\t\t<td".($gridSpace['cellType'] == 'base' || $gridSpace['cellType'] == 'branch' ? " class=\"base\"" : '').">&nbsp;</td>\n";
									}
								}
								echo "\t\t\t\t</tr>\n";
							}
							echo "\t\t\t</table>\n";
							if($bracket < count($brackets))
								echo "\t\t\t<p>&nbsp;</p>\n";
						}
					}
				} else {
					echo "\t\t\t<h1>Results</h1>\n";
					echo "\t\t\t<p><i>Event was closed with no entries.</i></p>\n";
				}
			}

			echo "\t\t\t<h1>Contestants and Preliminary Seeding</h1>\n";
			if(! isset($_GET['event']))
				echo "\t\t\t<p>This table shows the initial seeding (ranking) of all teams registered for this event. This seeding is used to assign teams to slots in the elimination table. Team ratings are calculated by taking the average individual player rating of all team members in <a href=\"http://leaguesunited.org\">Leagues United</a>, and are subject to change until registration is closed. Any teams listed in gray text without a seeding are on the waiting list (listed in order of priority) due to the number of teams exceeding the event capacity.</p>\n";
			echo "\t\t\t<table>\n";
			echo "\t\t\t\t<tr><th>Seed</th><th>Average&nbsp;Rating</th><th>Team&nbsp;Members&nbsp;&amp;&nbsp;Individual&nbsp;Ratings</th></tr>\n";
			$queryResult = $mysqli->query('SELECT team AS teamID,(SELECT sufficiencyTime FROM '.$mySQLPrefix.'teams WHERE id=teamID) as sufficiencyTime,FLOOR(SUM(rating) / COUNT(rating)) AS average, GROUP_CONCAT(CONCAT_WS(" ",(SELECT callsign FROM '.$mySQLPrefix.'users WHERE bzid='.$mySQLPrefix.'memberships.bzid),CONCAT("(",rating,")")) ORDER BY rating IS NULL SEPARATOR ",") AS members,((SELECT COUNT(*) FROM '.$mySQLPrefix.'memberships WHERE rating IS NOT NULL AND team=teamID) >= (SELECT minTeamSize FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent.')) AS enough,(SELECT maxTeams FROM '.$mySQLPrefix.'events WHERE id='.$currentEvent.') AS maxTeams FROM '.$mySQLPrefix.'memberships WHERE team IN (SELECT id FROM '.$mySQLPrefix.'teams WHERE event='.$currentEvent.') '.($isAdmin ? '' : 'AND rating IS NOT NULL ').'GROUP BY team ORDER BY average DESC,sufficiencyTime');
			if($queryResult && $queryResult->num_rows > 0) {
				$resultArray = $queryResult->fetch_all(MYSQLI_ASSOC);
				for($i = 0; $i < count($resultArray); ++$i) {
					$members = preg_split("/\,/", $resultArray[$i]['members']);
					for($p = 0; $p < count($members); ++$p)
						if(! preg_match("/\(\d+\)/", $members[$p]))
							$members[$p] = preg_replace("/^.*$/", "<span class=\"gray\">$0</span>", $members[$p]);
					$resultArray[$i]['members'] = implode(', ', $members);
				}
				$altRow = FALSE;
				$teamCount = 0;
				$sufficiencyTimes = Array();
				foreach($resultArray as $result)
					if($result['enough'])
						$sufficiencyTimes[$result['teamID']] = strtotime($result['sufficiencyTime']);
				asort($sufficiencyTimes);
				while(count($sufficiencyTimes) > $resultArray[0]['maxTeams'])
					array_pop($sufficiencyTimes);
				$grayList = Array();
				foreach($resultArray as $result) {
					if($result['enough'] && array_key_exists($result['teamID'], $sufficiencyTimes)) {
						echo "\t\t\t\t<tr".($altRow ? " class=\"altRow\"" : "")."><td class=\"rightAlign\">".++$teamCount."</td><td>".$result['average']."</td><td class=\"leftAlign\">".$result['members']."</td></tr>\n";
						$altRow = ! $altRow;
					} else if($result['enough'] || $isAdmin) {
						$realTime = $result['sufficiencyTime'] == '' ? 0 : strtotime($result['sufficiencyTime']);
						if(! array_key_exists($realTime, $grayList))
							$grayList[$realTime] = Array();
						array_push($grayList[$realTime], $result);
					}
				}
				ksort($grayList);
				foreach($grayList as $resultTime => $grayListLine) {
					foreach($grayListLine as $result) {
						if($resultTime != 0) {
							echo "\t\t\t\t<tr".($altRow ? " class=\"altRow\"" : "")."><td>&nbsp;</td><td class=\"gray\">".$result['average']."</td><td class=\"leftAlign gray\">".$result['members']."</td></tr>\n";
							$altRow = ! $altRow;
							++$teamCount;
						}
					}
				}
				$eventFull = $teamCount >= $resultArray[0]['maxTeams'];
				foreach($grayList as $resultTime => $grayListLine) {
					foreach($grayListLine as $result) {
						if($resultTime == 0) {
							echo "\t\t\t\t<tr".(($altRow || $eventFull) ? ' class="'.($altRow ? 'altRow'.($eventFull ? ' ' : '') : '').($eventFull ? 'gray' : '').'"' : '')."><td>&nbsp;</td><td>&nbsp;</td><td class=\"leftAlign\">".$result['members']."</td></tr>\n";
							$altRow = ! $altRow;
							++$teamCount;
						}
					}
				}
				if($teamCount == 0)
					echo "\t\t\t\t<tr><td colspan=\"3\" class=\"leftAlign\"><i>No teams are registered for this event yet.</i></td></tr>\n";
			} else {
				echo "\t\t\t\t<tr><td colspan=\"3\" class=\"leftAlign\"><i>No teams are registered for this event yet.</i></td></tr>\n";
			}
			echo "\t\t\t</table>\n";
		}
	} else {
		echo "\t\t\t<p><i>No events have yet been configured. Please check back later.</i></p>\n";
	}
} else if($databaseUp) {
	echo "\t\t\t<p>You must now configure administration acces to this site by specifying one or more global BZFlag groups to have administration privileges. Once you complete this step, you must log in again to establish your membership in one of these groups.</p>\n";
	echo "\t\t\t<p>Note that if you specify group(s) that you are not a member of, you will <b>lose access</b> to this site and will have to edit the MySQL database tables by hand. <b>Please type carefully.</b></p>\n";
	echo "\t\t\t\t<form action=\".?action=config\" method=\"POST\">\n";
	echo "\t\t\t\t\t<fieldset>\n";
	echo "\t\t\t\t\t\t<legend>Administrative Group(s)</legend>\n";
	echo "\t\t\t\t\t\t<p class=\"tight\">Group(s): <input name=\"groups\" type=\"text\" size=\"50\"> <i>(separate group names with spaces or commas)</i></p>\n";
	echo "\t\t\t\t\t\t<br>\n";
	echo "\t\t\t\t\t\t<p class=\"tight\"><input type=\"submit\" value=\"Configure\" class=\"submitButton\"></p>\n";
	echo "\t\t\t\t\t</fieldset>\n";
	echo "\t\t\t\t</form>\n";
} else {
	echo "\t\t\t<p class=\"error\"><b>Error:</b> The database was unreachable or did not contain the necessary table structure. Please ensure that your configuration file is in place and has the correct settings, and that the tables have been set up. Once these steps are complete, please refresh the page to log in and immediately secure your administration privileges.</p>\n";
}

/////////////////////////////// Content Footer ////////////////////////////////

echo "\t\t</div>\n";
echo "\t\t<div class=\"footerBox\">\n";
echo "\t\t\t<a href=\"https://github.com/macsforme/bzfmchallenge\">https://github.com/macsforme/bzfmchallenge</a>\n";
echo "\t\t</div>\n";
echo "\t</body>\n";
echo "</html>\n";

?>
