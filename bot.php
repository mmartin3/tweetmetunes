<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" 
  "http://www.w3.org/TR/html4/loose.dtd">
<html lang="en">
<head>
<title>Tweet Me Tunes bot</title>
<meta http-equiv="refresh" content="300">
</head>
<body>
<?php
require_once('twitteroauth/twitteroauth/twitteroauth.php');
include 'config.php';
include 'db_connect.php';
 
$connection = new TwitterOAuth($consumer_key, $consumer_secret , $oauth_token , $oauth_token_secret);
date_default_timezone_set("America/New York");
set_time_limit(300);
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

function recommend($conn, $u, $text, $reply_id, $lfm_key)
{	
	$query_music = str_replace("\"", "", split_query($text));
	$now_playing = search("%23nowplaying+$query_music");
	
	foreach ($now_playing as $result)
	{
		$also_playing = search("%23nowplaying&lang=en&nots=$query_music&from=".$result['from_user']);
		
		foreach ($also_playing as $result2)
		{
			$music_info = extract_music_info($result2['text'], $lfm_key);
		
			if (!empty($music_info['artist']) && !empty($music_info['track']))
			{
				write_to_log("Recommending ".$music_info['artist']." - ".$music_info['track']." to @$u.");
				
				$tweet_info = tweet($conn, "@$u You might like ".$music_info['artist']." - ".$music_info['track'].". ".get_youtube_link($music_info));
				
				if (empty($tweet_info['error'])) return true;
			}
			
			$music_info = array();
		}
	}
	
	write_to_log("Unable to find music similar to $query_music.");
	$tweet_info = tweet($conn, "@$u Sorry, I couldn't find any music similar to $query_music. Please try a different query.");
	if (empty($tweet_info['error'])) return true;
	
	return false;
}

function write_to_log($str)
{
	$str = "[".date("Y-m-d H:s")."] $str";
	echo "<p>$str</p>";
	$f = fopen("tmtlog.txt", 'a') or die("can't open file");
	fwrite($f, "$str\r\n");
	fclose($f);
}

function is_valid($query)
{
	write_to_log("Evaluating query $query.");
	$fmt = "@tweetmetunes like ";
	
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

function split_query($query)
{
	$fmt = "@tweetmetunes like ";
	$query = trim($query);
		
	return substr($query, strlen($fmt));
}

function get_artist_plays($artist, $lfm_key)
{
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
	if (stripos($tweet, "by") !== false) return "by";
	else if (stripos($tweet, "-") !== false) return "-";
	else if (stripos($tweet, "'s") !== false) return "'s";
	else if (stripos($tweet, "\"") !== false) return "\"";
	else return -1;
}

function extract_music_info($tweet, $lfm_key, $artist_not = "")
{
	$original_tweet = $tweet;
	$tweet = str_ireplace("#nowplaying", "", $tweet);
	$split_index = get_split_index($tweet);
	$tweet = str_ireplace($split_index, " $split_index ", $tweet);
	$music_info = array();
	
	if ($split_index == -1 || (stripos($tweet, "@") !== false))
		return $music_info; //this tweet is unusable, so return an empty array
	
	$split_index_pos = stripos($tweet, $split_index);
	$str_before = strip_feat(trim(substr($tweet, 0, $split_index_pos)));
	$str_after = strip_feat(trim(substr($tweet, $split_index_pos + strlen($split_index))));
	$words_before = array_reverse(explode(" ", $str_before));
	$words_after = explode(" ", $str_after);
	$terms_before = array();
	$terms_after = array();
	$str = "";
	$min_plays = 50000;
	
	if ((stripos($str_before, "http://") !== false) || (stripos($str_before, "www.") !== false) || 
		(stripos($str_before, ".com") !== false) || (stripos($str_after, "http://") !== false) ||
		(stripos($str_after, "www.") !== false) || (stripos($str_after, ".com") !== false) ||
		(count($words_before) > 10) || (count($words_after) > 10))
			return $music_info;
	
	foreach ($words_before as $word)
	{
		$str = trim($word)." $str";
		$str = trim($str);
		$play_count = get_artist_plays($str, $lfm_key);
		
		if ($play_count >= $min_plays)
		{
			write_to_log("$str is a valid artist with $play_count plays on Last.fm.");
			$terms_before[$str] = $play_count;
		}
			
		else
			write_to_log("$str is not a valid artist.");
	}
	
	$str = "";
	
	foreach ($words_after as $word)
	{
		$str .= " ".trim($word);
		$str = trim($str);
		$play_count = get_artist_plays($str, $lfm_key);
		
		if ($play_count >= $min_plays)
		{
			write_to_log("$str is a valid artist with $play_count plays on Last.fm.");
			$terms_after[$str] = $play_count;
		}
			
		else
			write_to_log("$str is not a valid artist.");
	}
	
	$most_plays = 0;
	$artist_loc = "before";
	
	foreach ($terms_before as $key => $value)
	{
		if (($value > $most_plays) && ($key != $artist_not) && (strlen($key) > 1) && (strcasecmp($key, "the") != 0))
		{
			$most_plays = $value;
			$artist_loc = "before";
			$music_info['artist'] = $key;
		}
	}
	
	foreach ($terms_after as $key => $value)
	{
		if (($value > $most_plays) && ($key != $artist_not) && (strlen($key) > 1) && (strcasecmp($key, "the") != 0))
		{
			$most_plays = $value;
			$artist_loc = "after";
			$music_info['artist'] = $key;
		}
	}
	
	if ($artist_loc == "before") $track_terms = $terms_after;
	else $track_terms = $terms_before;
	
	foreach ($track_terms as $key => $value)
	{
		$track_match = "";
		$artist_match = "";
		
		try
		{
			$xml = new SimpleXMLElement("http://ws.audioscrobbler.com/2.0/?method=track.search&track=".urlencode($key)."&artist=".urlencode($music_info['artist'])."&api_key=$lfm_key", NULL, true);
			$track_match = (string)($xml->results->trackmatches->track->name[0]);
			$artist_match = (string)($xml->results->trackmatches->track->artist[0]);
			
			if (stripos($artist_match, $music_info['artist']) !== false)
			{
				$music_info['track'] = $track_match;
				$music_info['artist'] = $artist_match;
			}
		}
		
		catch (Exception $e) {}
	}
	
	if (empty($music_info['track']) && ($most_plays >= $min_plays) && empty($artist_not))
	{
		write_to_log("Couldn't find a matching track for the artist ".$music_info['artist'].". Searching again for a different artist.");
		return extract_music_info($tweet, $lfm_key, $music_info['artist']); //extracted wrong artist, try again with a different one
	}
		
	else if ($most_plays < $min_plays) return array(); //this probably isn't a valid artist, so skip and return an empty array
	
	else
	{
		write_to_log("Parsed the tweet $original_tweet and found track ".$music_info['track']." by artist ".$music_info['artist'].".");
		return $music_info; //found artist and track
	}
}

function get_youtube_link($music_info)
{
	try
	{
		$xml = new SimpleXMLElement("http://gdata.youtube.com/feeds/api/videos?q=".urlencode($music_info['artist']." ".$music_info['track'])."&prettyprint=true&max-results=1", NULL, true);
		
		$link = (string)($xml->entry[0]->id);
		$short_link = "http://youtu.be".substr($link, strrpos($link, "/"));
		write_to_log("Found YouTube link for ".$music_info['artist']." - ".$music_info['track'].": $short_link");
		
		if ($short_link == "http://youtu.be") return "";
		
		return $short_link;
	}
	
	catch (Exception $e)
	{
		return "";
	}
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

$replies = search("to%3Atweetmetunes");
$reply_count = 0;
//var_dump($replies);

foreach ($replies as $reply)
{
	$user = mysql_escape_string($reply['from_user']);
	$time = mysql_escape_string($reply['created_at']);
	$result = mysqli_query($db, "SELECT * FROM reply WHERE `from` = '$user' AND `time` = '$time'");
	$is_new = true;
	
	while ($row = mysqli_fetch_assoc($result))
	{
		if (($row['recommended'] == 1) || ($row['query'] == 0))
			$is_new = false;
	}
	
	if ($is_new)
	{
		write_to_log("Found new @reply from user @$user: ".$reply['text']);
		
		mysqli_query($db, "INSERT INTO reply (`from`, `time`) VALUES ('$user', '$time')");
		$result = mysqli_query($db, "SELECT LAST_INSERT_ID() AS reply_id FROM reply");
		
		while ($row = mysqli_fetch_assoc($result))
		{
			$reply_id = $row['reply_id'];
		}
		
		if (is_valid($reply['text']))
		{
			mysqli_query($db, "UPDATE reply SET query = '1' WHERE id = '$reply_id'");
			
			if (recommend($connection, $user, $reply['text'], $reply_id, $last_fm_key))
				mysqli_query($db, "UPDATE reply SET recommended = '1' WHERE id = '$reply_id'");
				
			$reply_count++;
		}
		
		else
			mysqli_query($db, "UPDATE reply SET query = '0' WHERE id = '$reply_id'");
	}
}

if ($reply_count == 0) write_to_log("No new queries found.");
write_to_log("Done.");
?>
</body>
</html>