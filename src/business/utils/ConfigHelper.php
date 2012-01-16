<?php

class ConfigHelper
{
	public static function getTimeInSeconds($time, $default = 3600)
	{
		// if $time is just a number, assume it's already in seconds
		if (is_numeric($time))
			return $time;

		if (StringHelper::IsNullOrEmpty($time))
			return $default;

		if (!preg_match('/(\d+)(m|h|d)/', $time, $match))
		{
			// maybe it's a config key?
			$time = Blocks::app()->getConfig($time);
			if ($time !== null)
				return ConfigHelper::getTimeInSeconds($time);
			else
				return $default;
		}

		$seconds = $match[1];
		$unit = $match[2];

		switch ($unit)
		{
			case 'd':
				$seconds *= 24;
			case 'h':
				$seconds *= 60;
			case 'm':
				$seconds *= 60;
		}

		return $seconds;
	}
}
