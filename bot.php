<?php
$min_delay = 60;

if ((!empty($_GET['delay'])) && ($_GET['delay'] < $min_delay))
{
	header("location:bot.php?delay=$min_delay");
	exit;
}

$delay = $_GET['delay'];
if (empty($delay)) $delay = 300;
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" 
  "http://www.w3.org/TR/html4/loose.dtd">
<html lang="en">
<head>
<title>Tweet Me Tunes bot</title>
<meta http-equiv="refresh" content="<?php echo $delay ?>">
</head>
<body>
<?php
require_once('twitteroauth/twitteroauth/twitteroauth.php');
include 'config.php';
include 'db_connect.php';

$connection = new TwitterOAuth($consumer_key, $consumer_secret , $oauth_token , $oauth_token_secret);
date_default_timezone_set("America/New York");
set_time_limit($delay);
error_reporting(0);

function search($q)
{
	$search = file_get_contents("http://search.twitter.com/search.json?lang=en&q=$q");
	if ($search === false) die('Error occurred.');
	$result = json_decode($search, true);
	return $result['results'];
}

function tweet($conn, $status)
{
	write_to_log("Sending tweet: $status");
	$result = json_decode(json_encode($conn->post('statuses/update', array('status' => $status))), true);
	if (empty($result['error'])) write_to_log("Tweet posted successfully.");
	else write_to_log("Failed sending tweet. ".$result['error']);
	return $result;
}

function recommend($conn, $u, $text, $lfm_key, $settings)
{
	$query_music = split_query($text);
	$original_query = $query_music;
	$query_music = str_replace("\"", "", $query_music);
	$now_playing = search("%23nowplaying+OR+%23np+$query_music");
	
	write_to_log("Getting Last.fm data for $original_query...");
	$query_data = extract_music_data($conn, $query_music, $lfm_key, "", false, 100);
	
	if (!empty($query_data['artist']))
		$query_tags = get_tags_for_artist($query_data['artist'], $lfm_key);
		
	else
		return tweet($conn, "@$u Sorry, $query_music doesn't seem to be a valid artist. Please try a different query.");
	
	foreach ($now_playing as $result)
	{
		$also_playing = search("%23nowplaying+OR+%23np&lang=en&nots=$query_music&from=".$result['from_user']);
		
		foreach ($also_playing as $result2)
		{
			write_to_log("Found similar tweet: ".$result2['text']);
			$music_data = extract_music_data($conn, $result2['text'], $lfm_key);
		
			if (!empty($music_data['artist']) && !empty($music_data['track']))
			{
				if (empty($settings['dislikes']) || (!in_array($music_data['artist'], $settings['dislikes'])))
				{
					write_to_log("Recommending ".$music_data['artist']." - ".$music_data['track']." to @$u.");
				
					$tweet_info = tweet($conn, "@$u You might like ".$music_data['artist']." - ".$music_data['track'].". ".get_youtube_link($music_data));
				
					if (empty($tweet_info['error'])) return true;
				}
				
				else
					write_to_log("Skipped because @$u has given ".$music_data['artist']." a negative rating.");
			}
			
			else
				write_to_log("Could not extract music data.");
			
			$music_data = array();
		}
	}
	
	write_to_log("Unable to find music similar to $original_query.");
	$tweet_info = tweet($conn, "@$u Sorry, I couldn't find any music similar to $original_query. Please try a different query.");
	if (empty($tweet_info['error'])) return true;
	
	return false;
}

function write_to_log($str)
{
	$str = "[".date("Y-m-d H:s")."] $str";
	echo "<p>$str</p>\n";
	$f = fopen("tmtlog.txt", 'a') or die("can't open file");
	fwrite($f, "$str\r\n");
	fclose($f);
}

function is_valid($query)
{
	$query = trim(str_ireplace("@tweetmetunes", "", $query));
	write_to_log("Evaluating query $query.");
	$fmt = "like ";
	
	if ((stripos(trim($query), $fmt) === 0) && (strlen(trim($query)) > strlen($fmt)))
	{
		write_to_log("Validity confirmed.");
		return true;
	}
	
	else
	{
		write_to_log("Invalid query.");
		return false;
	}
}

function is_rating($query)
{
	if ((stripos(trim($query), "more like") === 0) || (stripos(trim($query), "less like") === 0))
	{
		write_to_log("$query is a valid rating tweet.");
		return true;
	}
	
	else
	{
		write_to_log("Not a valid rating.");
		return false;
	}
}

function split_query($query)
{
	$query = trim(str_ireplace("@tweetmetunes", "", $query));
	$fmt = "like ";
		
	return substr($query, strlen($fmt));
}

function get_artist_plays($artist, $lfm_key)
{
	if (empty($artist))
		return -1;
		
	try
	{
		$xml = new SimpleXMLElement("http://ws.audioscrobbler.com/2.0/?method=artist.getinfo&artist=".urlencode($artist)."&api_key=$lfm_key", NULL, true);
		
		return (int)($xml->artist->stats->playcount[0]);
	}
	
	catch (Exception $e)
	{
		return -1;
	}
}

function get_split_index($tweet)
{
	if (stripos($tweet, " by ") !== false) return " by ";
	else if (stripos($tweet, "-") !== false) return "-";
	else if (stripos($tweet, "'s") !== false) return "'s";
	else if (stripos($tweet, "&quot;") !== false) return "&quot;";
	else if (stripos($tweet, "~") !== false) return "~";
	else if (stripos($tweet, "/") !== false) return "/";
	else return "";
}

function extract_music_data($conn, $tweet, $lfm_key, $artist_not = "", $tokenize = true, $min_plays = 10000)
{
	$original_tweet = $tweet;
	$tweet = str_ireplace("#nowplaying", "", $tweet);
	$tweet = str_ireplace("#np", "", $tweet);
	$split_index = get_split_index($tweet);
	$tweet = str_ireplace($split_index, " $split_index ", $tweet);
	$music_data = array();
	$skip_track = false;
	
	if (empty($split_index))
		$skip_track = true;
	
	if ($skip_track)
	{
		$str_before = "";
		$str_after = strip_feat(trim($tweet));
		$words_before = array();
	}
		
	else
	{
		$split_index_pos = stripos($tweet, $split_index);
		$str_before = strip_feat(trim(substr($tweet, 0, $split_index_pos)));
		$str_after = strip_feat(trim(substr($tweet, $split_index_pos + strlen($split_index))));
		$words_before = array_reverse(explode(" ", $str_before));
	}
	
	$words_after = explode(" ", $str_after);
	$terms_before = array();
	$terms_after = array();
	$str = "";
	
	if ($tokenize)
	{
		foreach ($words_before as $word)
		{
			$word = trim($word);
			
			if (substr($word, 0, 1) == "@")
			{
				$user = lookup_user_by_name($conn, $word);
				$word = $user['name'];
			}
			
			$str = "$word $str";
			$str = trim($str);
			$play_count = get_artist_plays($str, $lfm_key);
			
			if ($play_count >= $min_plays)
			{
				write_to_log("$str is a valid artist with $play_count plays on Last.fm.");
				$terms_before[$str] = $play_count;
			}
				
			else if (!empty($str))
				write_to_log("$str is not a valid artist.");
		}
		
		$str = "";
		
		foreach ($words_after as $word)
		{
			$word = trim($word);
			
			if (substr($word, 0, 1) == "@")
			{
				$user = lookup_user_by_name($conn, $word);
				$word = $user['name'];
			}
			
			$str .= " ".$word;
			$str = trim($str);
			$play_count = get_artist_plays($str, $lfm_key);
			
			if ($play_count >= $min_plays)
			{
				write_to_log("$str is a valid artist with $play_count plays on Last.fm.");
				$terms_after[$str] = $play_count;
			}
				
			else if (!empty($str))
				write_to_log("$str is not a valid artist.");
		}
	}
	
	else
	{
		if (substr($str_before, 0, 1) == "@")
		{
			$user = lookup_user_by_name($conn, $str_before);
			$str_before = $user['name'];
		}
		
		if (substr($str_after, 0, 1) == "@")
		{
			$user = lookup_user_by_name($conn, $str_after);
			$str_after = $user['name'];
		}
		
		$play_count = get_artist_plays($str_before, $lfm_key);
		
		if ($play_count >= $min_plays)
		{
			write_to_log("$str_before is a valid artist with $play_count plays on Last.fm.");
			$terms_before[$str_before] = $play_count;
		}
				
		else if (!empty($str_before))
			write_to_log("$str_before is not a valid artist.");
		
		$play_count = get_artist_plays($str_after, $lfm_key);
		
		if ($play_count >= $min_plays)
		{
			write_to_log("$str_after is a valid artist with $play_count plays on Last.fm.");
			$terms_after[$str_after] = $play_count;
		}
				
		else if (!empty($str_after))
			write_to_log("$str_after is not a valid artist.");
	}
		
	if (!$skip_track)
	{
		$most_plays = 0;
		$artist_loc = "before";
		
		foreach ($terms_before as $key => $value)
		{
			if (($value > $most_plays) && ($key != $artist_not) && (strlen($key) > 1) && (strcasecmp($key, "the") != 0))
			{
				$most_plays = $value;
				$artist_loc = "before";
				$music_data['artist'] = $key;
			}
		}
		
		foreach ($terms_after as $key => $value)
		{
			if (($value > $most_plays) && ($key != $artist_not) && (strlen($key) > 1) && (strcasecmp($key, "the") != 0))
			{
				$most_plays = $value;
				$artist_loc = "after";
				$music_data['artist'] = $key;
			}
		}
		
		if ($artist_loc == "before") $track_words = $words_after;
		else $track_words = $words_before;
		$track_terms = array();
		$i = 0;
		$str = "";
		
		foreach ($track_words as $word)
		{
			$word = trim($word);
			
			if ($artist_loc == "after")
				$str = "$word $str";
				
			else
				$str .= " ".$word;
			
			$track_terms[$i++] = $str;
		}
		
		if (!empty($music_data['artist']))
		{
			foreach ($track_terms as $term)
			{
				$track_match = "";
				$artist_match = "";
				$term = str_replace("&quot;", "", $term);
				
				try
				{
					$xml = new SimpleXMLElement("http://ws.audioscrobbler.com/2.0/?method=track.search&track=".urlencode($term)."&artist=".urlencode($music_data['artist'])."&api_key=$lfm_key", NULL, true);
					$track_match = (string)($xml->results->trackmatches->track->name[0]);
					$artist_match = (string)($xml->results->trackmatches->track->artist[0]);
					
					if (strcasecmp($artist_match, $music_data['artist']) == 0)
					{
						$music_data['track'] = $track_match;
						$music_data['artist'] = $artist_match;
					}
				}
				
				catch (Exception $e) {}
				
				if ($track_match == "") write_to_log("$term is not a valid track by ".$music_data['artist'].".");
				else write_to_log("$track_match is a valid track by $artist_match.");
			}
		}
		
		if (empty($music_data['track']) && ($most_plays >= $min_plays) && empty($artist_not))
		{
			write_to_log("Couldn't find a matching track for the artist ".$music_data['artist'].". Searching again for a different artist.");
			return extract_music_data($conn, $tweet, $lfm_key, $music_data['artist']); //extracted wrong artist, try again with a different one
		}
			
		else if ($most_plays < $min_plays) return array(); //this probably isn't a valid artist, so skip and return an empty array
	
		else
			write_to_log("Parsed the tweet $original_tweet and found track ".$music_data['track']." by artist ".$music_data['artist'].".");
	}
	
	else if (get_artist_plays($str_after, $lfm_key) > $min_plays)
	{
			$music_data['artist'] = $str_after;
			write_to_log("Parsed the tweet $original_tweet and found artist ".$music_data['artist'].".");	
	}
	
	return $music_data; //found artist and/or track
}

function get_youtube_link($music_data)
{
	try
	{
		$xml = new SimpleXMLElement("http://gdata.youtube.com/feeds/api/videos?q=".urlencode($music_data['artist']." ".$music_data['track'])."&prettyprint=true&max-results=1", NULL, true);
		
		$link = (string)($xml->entry[0]->id);
		$short_link = "http://youtu.be".substr($link, strrpos($link, "/"));
		write_to_log("Found YouTube link for ".$music_data['artist']." - ".$music_data['track'].": $short_link");
		
		if ($short_link == "http://youtu.be") return "";
		
		return $short_link;
	}
	
	catch (Exception $e) {}
	
	return "";
}

function strip_feat($str)
{
	if (stripos($str, " ft") !== false)
		return trim(substr($str, 0, stripos($str, "ft")));
		
	if (stripos($str, "(ft") !== false)
		return trim(substr($str, 0, stripos($str, "(ft")));
		
	if (stripos($str, "[ft") !== false)
		return trim(substr($str, 0, stripos($str, "[ft")));
		
	if (stripos($str, "(feat") !== false)
		return trim(substr($str, 0, stripos($str, "(feat")));
		
	if (stripos($str, "[feat") !== false)
		return trim(substr($str, 0, stripos($str, "[feat")));
		
	if (stripos($str, " feat.") !== false)
		return trim(substr($str, 0, stripos($str, " feat.")));
		
	if (stripos($str, "featuring") !== false)
		return trim(substr($str, 0, stripos($str, "featuring")));
		
	return $str;
}

function list_direct_messages($conn)
{
	write_to_log("Retrieving direct messages...");
	return json_decode(json_encode($conn->get('direct_messages')), true);
}

function delete_message($conn, $id)
{
	write_to_log("Deleting direct message #$id.");
	return json_decode(json_encode($conn->post('direct_messages/destroy', array("id" => $id)), true));
}

function list_followers($conn)
{
	write_to_log("Checking followers...");
	return json_decode(json_encode($conn->get('followers/ids')), true);
}

function update_follows($conn)
{
	$new_follow_count = 0;
	$followers = list_followers($conn);
	
	foreach ($followers as $follower_id)
	{
		$user = lookup_user_by_id($conn, $follower_id);
		
		if ($user['following'] == false)
		{
			write_to_log("User @".$user['screen_name']." is now following @tweetmetunes.");
			json_decode(json_encode($conn->post('friendships/create', array("id" => $follower_id)), true));
			write_to_log("Sent follow request to @".$user['screen_name'].".");
			$new_follow_count++;
			
			$user_updated = lookup_user_by_id($conn, $follower_id);
		
			if ($user_updated['following'] == true)
				write_to_log("@tweetmetunes is now following user @".$user_updated['screen_name'].".");
		}
	}
	
	if ($new_follow_count == 0) write_to_log("No new followers.");
	
	return $followers;
}

function lookup_user_by_id($conn, $uid)
{
	$lookup = json_decode(json_encode($conn->get('users/lookup', array("user_id" => "$uid"))), true);
	return $lookup[0];
}

function lookup_user_by_name($conn, $screen_name)
{
	$screen_name = str_replace("@", "", $screen_name);
	$screen_name = str_replace(htmlspecialchars("@"), "", $screen_name);
	$screen_name = str_replace(urlencode("@"), "", $screen_name);
	$lookup = json_decode(json_encode($conn->get('users/lookup', array("screen_name" => $screen_name))), true);
	return $lookup[0];
}

function rate($conn, $db, $dm)
{
	$uid = mysql_escape_string($dm['sender_id'], $db);
	$u = $dm['sender_screen_name'];
	
	$fmt = " like ";
	$artist = mysql_escape_string(trim(substr($dm['text'], stripos($dm['text'], $fmt) + strlen($fmt))), $db);
	
	if (stripos($dm['text'], "more like ") !== false)
	{
		$rating = 1;
		$rating_desc = "more";
	}
	
	else if (stripos($dm['text'], "less like ") !== false)
	{
		$rating = -1;
		$rating_desc = "less";
	}
	
	if (empty($rating)) return false;
	
	if (!empty($u))
	{
		mysqli_query($db, "INSERT INTO rating (uid, artist, rating) VALUES ('$uid', '$artist', '$rating')");
		tweet($conn, "@$u Got it. From now on I'll tweet you tunes $rating_desc like $artist.");
		write_to_log("Recording explicit rating from user @$u for artist $artist.");
		return true;
	}
	
	else
		return false;
}

function get_user_settings($db, $uid)
{
	$likes = array();
	$dislikes = array();
	
	$result = mysqli_query($db, "SELECT * FROM rating WHERE uid = '$uid' AND rating > 0");
	$i = 0;
	
	while ($row = mysqli_fetch_assoc($result))
	{
		$likes[$i++] = $row['artist'];
	}
	
	$result = mysqli_query($db, "SELECT * FROM rating WHERE uid = '$uid' AND rating < 0");
	$i = 0;
	
	while ($row = mysqli_fetch_assoc($result))
	{
		$dislikes[$i++] = $row['artist'];
	}
	
	return array("likes" => $likes, "dislikes" => $dislikes);
}

function get_tags_for_artist($artist, $lfm_key)
{
	$count = 0;
	$tags = array();
	
	try
	{
		$xml = new SimpleXMLElement("http://ws.audioscrobbler.com/2.0/?method=artist.getTopTags&artist=".urlencode($artist)."&api_key=$lfm_key", NULL, true);
		
		for ($i = 0; $i <= 10; $i++)
		{
			if (count($tags) >= 10)
				break;
		
			$count = (int)($xml->toptags->tag[$i]->count);
			$tag_name = (string)($xml->toptags->tag[$i]->name);
				
			if ((!empty($tag_name)) && (strcasecmp($tag_name, $artist) != 0))
				$tags[$tag_name] = $count;
		}
	}
	
	catch (Exception $e) {}
	
	return $tags;
}


update_follows($connection);
$direct_messages = list_direct_messages($connection);
$dm_count = 0;

foreach ($direct_messages as $dm)
{
	$user = mysql_escape_string($dm['sender_screen_name']);
	$dm_id = mysql_escape_string($dm['id']);
	$settings = get_user_settings($db, mysql_escape_string($dm['sender_id']));
	write_to_log("Found new direct message from user @$user: ".$dm['text']);
	
	if (is_valid($dm['text']))
		recommend($connection, $user, $dm['text'], $last_fm_key, $settings);
	
	else if (is_rating($dm['text']))
		rate($connection, $db, $dm);
		
	delete_message($connection, $dm_id);
	$dm_count++;
}

if ($dm_count == 0) write_to_log("No new direct messages found.");

write_to_log("Done.");

echo "<center>\n";
echo "<br />\n";
echo "<form action='bot.php' method='get'>\n";
echo "<p><b>Set delay:</b>&nbsp;<input style='width: 40px;' name='delay' value='$delay' />&nbsp;seconds&nbsp;&nbsp;";
echo "<input type='submit' value=' Submit ' /></p>\n";
echo "</form>\n";
echo "<br />\n";
echo "<p>";
echo "<a href='http://tinyurl.com/tweetmetunes' target='_blank'>Tweet Me Tunes portal</a> / ";
echo "<a href='http://twitter.com/tweetmetunes' target='_blank'>@tweetmetunes on Twitter</a> / ";
echo "<a href='bot.php?delay=$delay'>Tweet Me Tunes bot (run again)</a> / ";
echo "<a href='https://github.com/mmartin3/tweetmetunes' target='_blank'>Source @ GitHub</a> / ";
echo "<a href='tmtlog.txt' target='_blank'>Activity log</a>";
echo "</p>\n";
echo "<br />\n";
echo "</center>\n";
?>
</body>
</html>