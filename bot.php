<?php
$exec_time = microtime(true);
$min_delay = 60;

if ((!empty($_GET['delay'])) && ($_GET['delay'] < $min_delay))
{
	header("location:bot.php?delay=$min_delay");
	exit;
}

$delay = $_GET['delay'];
if (empty($delay)) $delay = 600;
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

$conn = new TwitterOAuth($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret);
write_to_log("Connected to Twitter via oAuth.");
date_default_timezone_set("America/New York");
set_time_limit($delay);
error_reporting(0);

include 'distance.php';

function search($q)
{
	$search = file_get_contents("http://search.twitter.com/search.json?lang=en&q=$q");
	if ($search === false) die('Error occurred.');
	$result = json_decode($search, true);
	return $result['results'];
}

function tweet($status)
{
	global $conn;
	
	write_to_log("Sending tweet: $status");
	$result = json_decode(json_encode($conn->post('statuses/update', array('status' => $status))), true);
	if (empty($result['error'])) write_to_log("Tweet posted successfully.");
	else write_to_log("Failed sending tweet. ".$result['error']);
	return $result;
}

function recommend($u, $text, $settings)
{
	global $conn, $last_fm_key;
	
	$query_music = split_query($text);
	$original_query = $query_music;
	$query_music = str_replace("\"", "", $query_music);
	$matches = array();
	$match_count = 1;
	
	write_to_log("Getting Last.fm data for $original_query...");
	$query_data = extract_music_data(stripslashes($query_music), "", false, 100);
	
	if (empty($query_data['artist']))
		return tweet("@$u Sorry, $query_music doesn't seem to contain a valid artist. Please try a different query.");
		
	$now_playing = search("%23nowplaying+OR+%23np+".$query_data['artist']."+".$query_data['track']);
	
	foreach ($now_playing as $result)
	{
		if ($match_count > 5)
				break;
				
		$also_playing = search("%23nowplaying+OR+%23np&nots=".$query_data['artist']."&from=".$result['from_user']);
		
		foreach ($also_playing as $result2)
		{
			if ($match_count > 5)
				break;
			
			write_to_log("Found similar tweet: ".$result2['text']);
			$music_data = extract_music_data($result2['text']);
		
			if (!empty($music_data['artist']) && !empty($music_data['track']))
			{
				if (empty($settings['dislikes']) || (!in_array($music_data['artist'], $settings['dislikes'])))
				{
					$music_data['match_no'] = $match_count;
					$matches[$match_count] = $music_data;
					write_to_log("Saved match #$match_count: ".$music_data['artist']." - ".$music_data['track'].".");
					$match_count++;
				}
				
				else
					write_to_log("Skipped because @$u has given ".$music_data['artist']." a negative rating.");
			}
			
			else
				write_to_log("Could not extract music data.");
			
			$music_data = array();
		}
	}
	
	if (!empty($matches))
	{
		$distances = array();
		$query_tags = get_tags_for_artist($query_data['artist']);
		
		foreach ($matches as $match)
		{
			$match_tags = get_tags_for_artist($match['artist']);
			$match_num = $match['match_no'];
			$pearson = pearson($query_tags, $match_tags);
			$pearson_base = $pearson;
			$like_count = 0;
			$dislike_count = 0;
			write_to_log("#$match_num: Pearson correlation coefficient for ".$match['artist']." with respect to ".$query_data['artist'].": $pearson");
			
			foreach ($settings['likes'] as $like)
			{
				$like_tags = get_tags_for_artist($like);
				$p2 = pearson($like_tags, $match_tags);
				write_to_log("#$match_num: Pearson correlation coefficient for ".$match['artist']." with respect to $like (liked by @$u): $p2");
				$pearson += $p2;
				$like_count++;
			}
			
			foreach ($settings['dislikes'] as $dislike)
			{
				$dislike_tags = get_tags_for_artist($dislike);
				$p2 = pearson($dislike_tags, $match_tags) * -1;
				write_to_log("#$match_num: Pearson correlation coefficient for ".$match['artist']." with respect to $dislike (disliked by @$u): $p2");
				$pearson += $p2;
				$dislike_count++;
			}
			
			if (($like_count + $dislike_count) > 0)
				$pearson /= (($like_count + $dislike_count) + 1);
			
			if (($pearson != $pearson_base) && !empty($pearson))
				write_to_log("#$match_num: Adjusted pearson for ".$match['artist']." with respect to ".$query_data['artist']." and explicit ratings: $pearson");
			
			$distances[$match_num] = $pearson;
		}
		
		$k = 5;
		if (count($distances) < 5) $k = count($distances);
		
		$nearest_neighbors = get_nearest_neighbors($distances, $k);
		
		foreach ($nearest_neighbors as $key => $value)
		{
			if ($value < 1)
			{
				$music_data = $matches[$key];
				write_to_log("Recommending ".$music_data['artist']." - ".$music_data['track']." to @$u.");
					
				$tweet_info = tweet("@$u You might like ".$music_data['artist']." - ".$music_data['track'].". ".get_youtube_link($music_data));
				
				if (empty($tweet_info['error'])) return true;
			}
		}
	}
	
	write_to_log("Unable to find music similar to $original_query.");
	$tweet_info = tweet("@$u Sorry, I couldn't find any music similar to ".stripslashes($original_query).". Please try a different query.");
	if (empty($tweet_info['error'])) return true;
		
	return false;
}

function write_to_log($str)
{
	global $exec_time;
	static $start_time, $elapsed_time;
	
	if (empty($start_time))
		$start_time = $exec_time;
	
	$timestamp = "[".date("Y-m-d H:s")."]";
	$str = "$timestamp $str";
	echo "<p>$str</p>\n";
	$f = fopen("tmtlog.txt", 'a') or die("can't open file");
	fwrite($f, "$str\r\n");
	fclose($f);
	
	$end_time = microtime(true);
	$op_time = $end_time - $start_time;
	$elapsed_time += $op_time;
	$start_time = $end_time;
	echo "<span style='visibility: hidden;'>$timestamp</span> <i>";
	if ($op_time > 1) echo "<span style='color: red;'>";
	echo round($op_time, 4)." seconds (Elapsed execution time: ".round($elapsed_time, 4)." seconds)";
	if ($op_time > 1) echo "</span>";
	echo "</i>\n";
}

function is_valid($query)
{
	$query = trim($query);
	$fmt = "like ";
	
	if ((stripos(trim($query), $fmt) === 0) && (strlen(trim($query)) > strlen($fmt)))
		return true;
	
	else
		return false;
}

function is_rating($query)
{
	$query = trim($query);
	
	if ((stripos($query, "more like") === 0) || (stripos($query, "less like") === 0))
		return true;
	
	else
		return false;
}

function is_reset($query)
{
	$query = trim($query);
	$fmt = "reset ";
	
	if ((stripos($query, $fmt) === 0) && (strlen($query) > strlen($fmt)))
		return true;
	
	else
		return false;
}

function split_query($query)
{
	$query = trim(str_ireplace("@tweetmetunes", "", $query));
	$fmt = "like ";
		
	return substr($query, strlen($fmt));
}

function get_artist_plays($artist)
{
	global $last_fm_key;
	
	if (empty($artist))
		return -1;
		
	try
	{
		$xml = new SimpleXMLElement("http://ws.audioscrobbler.com/2.0/?method=artist.getinfo&artist=".urlencode($artist)."&api_key=$last_fm_key", NULL, true);
		
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
	else return "";
}

function extract_music_data($tweet, $artist_not = "", $tokenize = true, $min_plays = 10000)
{
	global $conn, $last_fm_key;
	
	$original_tweet = $tweet;
	$tweet = str_ireplace("#nowplaying", "", $tweet);
	$tweet = str_ireplace("#np", "", $tweet);
	$split_index = get_split_index($tweet);
	$tweet = str_ireplace($split_index, " $split_index ", $tweet);
	$tweet = str_ireplace(".", "", $tweet);
	$tweet = str_ireplace("!", "", $tweet);
	$tweet = str_ireplace("?", "", $tweet);
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
	$word_count = 0;
	
	if ($tokenize)
	{
		$letters_and_numbers = range('a', 'z');
		$letters_and_numbers = array_merge($letters_and_numbers, range('a', 'z'));
		$letters_and_numbers = array_merge($letters_and_numbers, range('0', '9'));
	
		foreach ($words_before as $word)
		{
			$word = trim($word);
			$first_char = substr($word, 0, 1);
			
			if ($first_char == "@")
			{
				$user = lookup_user_by_name($word);
				$word = $user['name'];
			}
			
			else if ((!in_array($first_char, $letters_and_numbers) && (strlen($word) == 1)) || (stripos($word, "http:") !== false) || $word_count >= 10)
				break;
			
			$str = "$word $str";
			$str = trim($str);
			$play_count = get_artist_plays($str);
			
			if ($play_count >= $min_plays)
			{
				write_to_log("$str is a valid artist with $play_count plays on Last.fm.");
				$terms_before[$str] = $play_count;
			}
				
			else if (!empty($str))
				write_to_log("$str is not a valid artist.");
				
			$word_count++;
		}
		
		$str = "";
		$word_count = 0;
		
		foreach ($words_after as $word)
		{
			$word = trim($word);
			$first_char = substr($word, 0, 1);
			
			if ($first_char == "#")
				continue;
			
			if ($first_char == "@")
			{
				$user = lookup_user_by_name($word);
				$word = $user['name'];
			}
			
			else if ((!in_array($first_char, $letters_and_numbers) && (strlen($word) == 1)) || (stripos($word, "http:") !== false) || $word_count >= 10)
				break;
			
			$str .= " ".$word;
			$str = trim($str);
			$play_count = get_artist_plays($str);
			
			if ($play_count >= $min_plays)
			{
				write_to_log("$str is a valid artist with $play_count plays on Last.fm.");
				$terms_after[$str] = $play_count;
			}
				
			else if (!empty($str))
				write_to_log("$str is not a valid artist.");
				
			$word_count++;
		}
	}
	
	else
	{
		if (substr($str_before, 0, 1) == "@")
		{
			$user = lookup_user_by_name($str_before);
			$str_before = $user['name'];
		}
		
		if (substr($str_after, 0, 1) == "@")
		{
			$user = lookup_user_by_name($str_after);
			$str_after = $user['name'];
		}
		
		$play_count = get_artist_plays($str_before);
		
		if ($play_count >= $min_plays)
		{
			write_to_log("$str_before is a valid artist with $play_count plays on Last.fm.");
			$terms_before[$str_before] = $play_count;
		}
				
		else if (!empty($str_before))
			write_to_log("$str_before is not a valid artist.");
		
		$play_count = get_artist_plays($str_after);
		
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
		
		if (!$tokenize && !$skip_track)
		{
			if ($artist_loc == "before")
				$music_data['track'] = $str_after;
			
			if ($artist_loc == "after")
				$music_data['track'] = $str_before;
			
			write_to_log("Found track ".$music_data['track']." by artist ".$music_data['artist'].".");
			return $music_data;
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
				if (stripos($term, "#") !== false)
					continue;
				
				$track_match = "";
				$artist_match = "";
				$term = simplify_title($term); //strips things like "bonus track" and "main version" from the string
				$term = str_ireplace("&quot;", "", $term);
				$term = str_ireplace(" $split_index ", $split_index, $term);
				write_to_log("Searching Last.fm for track matching $term by artist ".$music_data['artist']."...");
				
				try
				{
					$xml = new SimpleXMLElement("http://ws.audioscrobbler.com/2.0/?method=track.search&track=".urlencode($term)."&artist=".urlencode($music_data['artist'])."&limit=1&api_key=$last_fm_key", NULL, true);
					$track_match = (string)($xml->results->trackmatches->track->name[0]);
					$artist_match = (string)($xml->results->trackmatches->track->artist[0]);
					$track_match = simplify_title($track_match);
					
					if ((stripos($track_match, "www.") !== false) || (stripos($track_match, "http:") !== false))
						$track_match = ""; //prevents the bot from recommending spammed ID3 tags
						
					$same_artist = (strcasecmp($artist_match, $music_data['artist']) == 0) || (strcasecmp("the $artist_match", $music_data['artist']) == 0) || (strcasecmp($artist_match, "the ".$music_data['artist']) == 0);
					
					if ($same_artist)
					{
						$music_data['track'] = $track_match;
						$music_data['artist'] = $artist_match;
					}
				}
				
				catch (Exception $e) {}
				
				if (!empty($track_match) && $same_artist)
					write_to_log("$track_match is a track by $artist_match.");
				
				else
					write_to_log("$term does not match any tracks by ".$music_data['artist'].".");
			}
		}
		
		if (empty($music_data['track']) && ($most_plays >= $min_plays) && empty($artist_not))
		{
			write_to_log("Couldn't find a matching track for the artist ".$music_data['artist'].". Searching again for a different artist.");
			return extract_music_data($tweet, $music_data['artist']); //extracted wrong artist, try again with a different one
		}
			
		else if ($most_plays < $min_plays) return array(); //this probably isn't a valid artist, so skip and return an empty array
	
		else if (!empty($music_data['track']))
			write_to_log("Parsed the tweet $original_tweet and found track ".$music_data['track']." by artist ".$music_data['artist'].".");
	}
	
	else if (get_artist_plays($str_after) > $min_plays)
	{
			$music_data['artist'] = $str_after;
			write_to_log("Parsed $original_tweet and found artist ".$music_data['artist'].".");
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

function simplify_title($str)
{
	if ((strrpos($str, "(") !== false) && (stripos($str, "track)") !== false))
		return trim(substr($str, 0, strrpos($str, "(")));
		
	if ((strrpos($str, "[") !== false) && (stripos($str, "track]") !== false))
		return trim(substr($str, 0, strrpos($str, "[")));
		
	if ((strrpos($str, "(") !== false) && (stripos($str, "main version)") !== false))
		return trim(substr($str, 0, strrpos($str, "(")));
		
	if ((strrpos($str, "[") !== false) && (stripos($str, "main version]") !== false))
		return trim(substr($str, 0, strrpos($str, "[")));
		
	if ((strrpos($str, "(") !== false) && (stripos($str, ".com)") !== false))
		return trim(substr($str, 0, strrpos($str, "(")));
		
	if ((strrpos($str, "(www") !== false) || (strrpos($str, "(http") !== false))
		return trim(substr($str, 0, strrpos($str, "(")));
		
	if ((strrpos($str, "- www") !== false) || (strrpos($str, "- http") !== false))
		return trim(substr($str, 0, strrpos($str, "-")));
	
	return $str;		
}

function list_direct_messages()
{
	global $conn;
	
	write_to_log("Retrieving direct messages...");
	return array_reverse(json_decode(json_encode($conn->get('direct_messages')), true));
}

function delete_message($id)
{
	global $conn;
	
	write_to_log("Deleting direct message #$id.");
	return json_decode(json_encode($conn->post('direct_messages/destroy', array("id" => $id)), true));
}

function list_followers($new_only = false)
{
	global $conn;
	
	write_to_log("Checking followers...");
	
	if ($new_only)
		return json_decode(json_encode($conn->get('followers/ids', array("following" => false)), true));
	
	return json_decode(json_encode($conn->get('followers/ids')), true);
}

function update_follows()
{
	global $conn;
	
	$new_follow_count = 0;
	$followers = list_followers(true);
	
	foreach ($followers as $follower_id)
	{
		$user = lookup_user_by_id($follower_id);
		
		if (!empty($user['screen_name']))
		{
			if ($user['following'] == false)
			{
				write_to_log("User @".$user['screen_name']." is now following @tweetmetunes.");
				json_decode(json_encode($conn->post('friendships/create', array("id" => $follower_id)), true));
				write_to_log("Sent follow request to @".$user['screen_name'].".");
				$new_follow_count++;
				
				$user_updated = lookup_user_by_id($follower_id);
			
				if ($user_updated['following'] == true)
					write_to_log("@tweetmetunes is now following user @".$user_updated['screen_name'].".");
			}
			
			else
				break;
		}
	}
	
	if ($new_follow_count == 0) write_to_log("No new followers.");
	
	return $followers;
}

function lookup_user_by_id($uid)
{
	global $conn;
	
	$lookup = json_decode(json_encode($conn->get('users/lookup', array("user_id" => "$uid"))), true);
	return $lookup[0];
}

function lookup_user_by_name($screen_name)
{
	global $conn;
	
	$screen_name = str_replace("@", "", $screen_name);
	$screen_name = str_replace(htmlspecialchars("@"), "", $screen_name);
	$screen_name = str_replace(urlencode("@"), "", $screen_name);
	$lookup = json_decode(json_encode($conn->get('users/lookup', array("screen_name" => $screen_name))), true);
	return $lookup[0];
}

function rate($text, $u, $uid)
{
	global $conn, $db;
	
	$fmt = " like ";
	$artist = trim(substr($text, stripos($text, $fmt) + strlen($fmt)));
	
	if (stripos($text, "more like ") !== false)
	{
		$rating = 1;
		$rating_desc = "more";
	}
	
	else if (stripos($text, "less like ") !== false)
	{
		$rating = -1;
		$rating_desc = "less";
	}
	
	if (empty($rating))
		return false;
	
	if (!empty($u))
	{
		mysqli_query($db, "INSERT INTO rating (uid, artist, rating) VALUES ('$uid', '$artist', '$rating')");
		tweet("@$u Got it. From now on I'll tweet you tunes $rating_desc like $artist.");
		write_to_log("Recording explicit rating from user @$u for artist $artist.");
		return true;
	}
	
	else
		return false;
}

function get_user_settings($uid)
{
	global $db;
	
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

function get_tags_for_artist($artist)
{
	global $last_fm_key;
	
	if (empty($artist)) return array();
	
	$count = 0;
	$tags = array();
	$tag_str = "Last.fm tags for artist $artist: ";
	
	try
	{
		$xml = new SimpleXMLElement("http://ws.audioscrobbler.com/2.0/?method=artist.getTopTags&artist=".urlencode($artist)."&autocorrect=1&api_key=$last_fm_key", NULL, true);
		
		for ($i = 0; $i < 50; $i++)
		{		
			$count = (int)($xml->toptags->tag[$i]->count);			
			$tag_name = (string)($xml->toptags->tag[$i]->name);
			
			if ($count == 0)
				break;
				
			if ((!empty($tag_name)) && (strcasecmp($tag_name, $artist) != 0))
			{
				$tags[$tag_name] = $count;
				if ($i > 0) $tag_str .= ", ";
				$tag_str .= "$tag_name ($count)";
			}
		}
	}
	
	catch (Exception $e) {}
	
	write_to_log("$tag_str");
	return $tags;
}

function reset_prefs($tweet, $u, $uid)
{
	global $db;
	
	$reset_all = (stripos($tweet, "reset all") !== false);
	
	if ((stripos($tweet, " ratings") !== false) || $reset_all)
	{
		mysqli_query($db, "DELETE FROM rating WHERE uid = '$uid'");
		write_to_log("Reset ratings for user $u.");
	}
	
	return tweet("@$u Your preferences were reset at ".date("g:i A")." on ".date("M j, Y").".");
}

update_follows();
$direct_messages = list_direct_messages();
$dm_count = 0;

foreach ($direct_messages as $dm)
{
	$text = mysql_escape_string($dm['text']);
	$user = mysql_escape_string($dm['sender_screen_name']);
	$uid = mysql_escape_string($dm['sender_id']);
	$dm_id = mysql_escape_string($dm['id']);
	$settings = get_user_settings($uid);
	write_to_log("Found new direct message from user @$user: $text");
	
	if (is_rating($text))
		rate($text, $user, $uid);
		
	else if (is_valid($text))
		recommend($user, $text, $settings);
		
	else if (is_reset($text))
		reset_prefs($text, $user, $uid);
		
	delete_message($dm_id);
	$dm_count++;
}

if ($dm_count == 0) write_to_log("No new direct messages found.");

write_to_log("Done.");

echo "<center>\n";
echo "<br />\n";
echo "<p><b>Total execution time:</b> ".round(microtime(true) - $exec_time, 1)." seconds</p>\n";
echo "<form action='bot.php' method='get'>\n";
echo "<p><b>Set delay:</b>&nbsp;<input style='width: 40px;' name='delay' value='$delay' />&nbsp;seconds&nbsp;&nbsp;";
echo "<input type='submit' value=' Submit ' /></p>\n";
echo "</form>\n";
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