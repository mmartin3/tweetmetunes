<?php
function pearson($tags1, $tags2)
{
$sum_xy = 0;
$sum_x = 0;
$sum_y = 0;
$sum_x2 = 0;
$sum_y2 = 0;
$n = 0;

    foreach ($tags1 as $key => $value)
    {
        if (array_key_exists($key, $tags2))
        {
			$n += 1;
			$x = $tags1[$value];
			$y = $tags2[$value];
			$sum_xy += $x * $y;
            $sum_x += $x;
            $sum_y += $y;
			$sum_x2 += $x * $x;
            $sum_y2 += $y * $y;
		}
	if ($n==0)
		return 0;
	else
		$denominator = sqrt($sum_x2 - pow($sum_x,2) / $n) * sqrt($sum_y2 - pow($sum_y2 - $sum_y, 2) / $n);
		if ($denominator == 0)
			return 0;
		else
			return ($sum_xy - ($sum_x * $sum_y) / $n) / $denominator;
	}

    
}

function compute_nearest_neighbors($arr, $k)
{

}
?>