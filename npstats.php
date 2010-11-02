<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" 
  "http://www.w3.org/TR/html4/loose.dtd">
<html lang="en">
<head>
<title>#nowplaying Tweet Statistics</title>
</head>
<body>
<?php
set_time_limit(300); //allows 5 minutes for the script to execute before timing out

function search($q)
{
	$search = file_get_contents("http://search.twitter.com/search.json?lang=en&q=$q"); //gets the JSON representation of the search results
	if ($search === false) die('Error occurred.');
	$result = json_decode($search, true); //converts the JSON object to a PHP associative array
	return $result['results']; //returns the array of search results
}

$now_playing = array(); //initializes the list of #nowplaying tweets as an empty array

//searches for and adds tweets to the array 100 at a time
for ($i = 15; $i > 0; $i--)
{
	$now_playing = array_merge($now_playing, search(urlencode("#nowplaying")."&rpp=100&page=$i"));
}

$count_dash = 0; //the number of tweets containing a dash
$count_by = 0; //the number of tweets containing "by"
$count_apos = 0; //the number of tweets containing an apostrophe + s
$count_quot = 0; //the number of tweets containing quotation marks
$count_other = 0; //the number of tweets containing none of these

//for each tweet in the list of #nowplaying tweets...
foreach ($now_playing as $tweet)
{
	if (stripos($tweet['text'], "-") !== false) $count_dash++; //if the tweet contains a dash, increment the dash counter
	if (stripos($tweet['text'], " by ") !== false) $count_by++; //if the tweet contains " by ", increment the by counter
	if (stripos($tweet['text'], "'s") !== false) $count_apos++; //if the tweet contains "'s," increment the apostrophe + s counter
	
	$quot_first_pos = strpos($tweet['text'], "&quot;"); //the index of the first occurence of a quotation mark in the tweet
	$quot_last_pos = strrpos($tweet['text'], "&quot;"); //the index of the last occurence of a quotation mark in the tweet
	
	//if there is are two different occurences (indices) for quotation marks, increment the quote counter
	if (($quot_first_pos !== false) && ($quot_last_pos !== false) && ($quot_first_pos != $quot_last_pos))
		$count_quot++;
	
	//if none of the other counters have changed since the last iteration, increment the other counter
	if (($dash_prev == $count_dash) && ($by_prev == $count_by) && ($apos_prev == $count_apos) && ($quot_prev == $count_quot))
		$count_other++;
		
	//saves the new counter values for comparison later
	$dash_prev = $count_dash;
	$by_prev = $count_by;
	$apos_prev = $count_apos;
	$quot_prev = $count_quot;
}

$total = count($now_playing); //the total number of tweets in the #nowplaying array

//converts the counts to percentages
$pct_dash = round(($count_dash * 100) / $total, 1);
$pct_by = round(($count_by * 100) / $total, 1);
$pct_apos = round(($count_apos * 100) / $total, 1);
$pct_quot = round(($count_quot * 100) / $total, 1);
$pct_other = round(($count_other * 100) / $total, 1);

//outputs the results
echo "<p>$count_dash of $total ($pct_dash%) contain a <b>dash (\"-\")</b>.</p>\n";
echo "<p>$count_by of $total ($pct_by%) contain the word <b>\"by\"</b>.</p>\n";
echo "<p>$count_quot of $total ($pct_quot%) contain <b>quotation marks (\"...\")</b>.</p>\n";
echo "<p>$count_apos of $total ($pct_apos%) contain an <b>apostrophe + s</b>.</p>\n";
echo "<p>$count_other of $total ($pct_other%) contain <b>none of these</b>.</p>\n";

//outputs a dynamic bar graph displaying the percentages using Google's Chart API
echo "<center>\n<img src=\"http://chart.apis.google.com/chart?chxr=0,0,100&chxt=y&chbh=a&chs=600x250&cht=bvg&chco=0000FF,008000,FF0000,990066,FF9900&chds=0,100,0,100,0,100,0,100,0,100&chd=t:$pct_dash|$pct_by|$pct_quot|$pct_apos|$pct_other&chdl=dash|%22by%22|quotation+marks|'s|other&chma=0,0,9,2|0,14&chtt=%23nowplaying+Tweet+Formatting\" width=\"600\" height=\"250\" alt=\"#nowplaying Tweet Formatting\" />\n</center>\n";
?>
</body>
</html>