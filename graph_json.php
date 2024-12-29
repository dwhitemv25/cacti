<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2024 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/* since we'll have additional headers, tell php when to flush them */
ob_start();

$guest_account = true;
$auth_json     = true;
$gtype         = 'png';

include('./include/auth.php');
include_once('./lib/rrd.php');

api_plugin_hook_function('graph_image');

/* set the json variable for request validation handling */
set_request_var('json', true);

$debug = false;

if ($debug == false) {
	/* ================= input validation ================= */
	get_filter_request_var('graph_start');
	get_filter_request_var('graph_end');
	get_filter_request_var('graph_height');
	get_filter_request_var('graph_width');
	get_filter_request_var('local_graph_id');

	if (isset_request_var('graph_nolegend')) {
		set_request_var('graph_nolegend', 'true');
	}

	get_filter_request_var('graph_theme', FILTER_CALLBACK, array('options' => 'sanitize_search_string'));
	/* ==================================================== */
} else {
	set_request_var('graph_width', 700);
	set_request_var('graph_height', 200);
	set_request_var('title_font_size', 10);
	set_request_var('view_type', 'tree');
	set_request_var('graph_start', -1600);
	set_request_var('graph_end', 0);
	set_request_var('local_graph_id', 53);
	set_request_var('rra_id', 0);
}

cacti_session_close();

$graph_data_array = array();

/* override: graph start time (unix time) */
if (!isempty_request_var('graph_start') && get_request_var('graph_start') < FILTER_VALIDATE_MAX_DATE_AS_INT) {
	$graph_data_array['graph_start'] = get_request_var('graph_start');
}

/* override: graph end time (unix time) */
if (!isempty_request_var('graph_end') && get_request_var('graph_end') < FILTER_VALIDATE_MAX_DATE_AS_INT) {
	$graph_data_array['graph_end'] = get_request_var('graph_end');
}

/* override: graph height (in pixels) */
if (!isempty_request_var('graph_height') && get_request_var('graph_height') < 3000) {
	$graph_data_array['graph_height'] = get_request_var('graph_height');
}

/* override: graph width (in pixels) */
if (!isempty_request_var('graph_width') && get_request_var('graph_width') < 3000) {
	$graph_data_array['graph_width'] = get_request_var('graph_width');
}

/* override: skip drawing the legend? */
if (!isempty_request_var('graph_nolegend')) {
	$graph_data_array['graph_nolegend'] = get_request_var('graph_nolegend');
}

/* print RRDtool graph source? */
if (!isempty_request_var('show_source')) {
	$graph_data_array['print_source'] = get_request_var('show_source');
}

/* disable cache check */
if (isset_request_var('disable_cache')) {
	$graph_data_array['disable_cache'] = true;
}

/* set the theme */
if (isset_request_var('graph_theme')) {
	$graph_data_array['graph_theme'] = get_request_var('graph_theme');
}

if (isset_request_var('rra_id')) {
	if (get_nfilter_request_var('rra_id') == 'all') {
		$rra_id = 'all';
	} else {
		$rra_id = get_filter_request_var('rra_id');
	}
} else {
	$rra_id = null;
}

$graph_data_array['graphv'] = true;

// Determine the graph type of the output
if (!isset_request_var('image_format')) {
	$type   = db_fetch_cell_prepared('SELECT image_format_id
		FROM graph_templates_graph
		WHERE local_graph_id = ?',
		array(get_request_var('local_graph_id')));

	switch($type) {
		case '1':
			$gtype = 'png';

			break;
		case '3':
			$gtype = 'svg+xml';

			break;

		default:
			$gtype = 'png';

			break;
	}
} else {
	switch(strtolower(get_nfilter_request_var('image_format'))) {
		case 'png':
			$graph_data_array['image_format'] = 'png';

			break;
		case 'svg':
			$gtype = 'svg+xml';

			break;

		default:
			$gtype = 'png';

			break;
	}
}

$graph_data_array['image_format'] = $gtype;

if ($config['poller_id'] == 1 || read_config_option('storage_location')) {
	$xport_meta = array();

	$output = rrdtool_function_graph(get_request_var('local_graph_id'), $rra_id, $graph_data_array, null, $xport_meta, $_SESSION[SESS_USER_ID]);

	ob_end_clean();
} else {
	if (isset_request_var('rra_id')) {
		if (get_nfilter_request_var('rra_id') == 'all') {
			$rra_id = 'all';
		} else {
			$rra_id = get_filter_request_var('rra_id');
		}
	}

	/* get the theme */
	if (!isset_request_var('graph_theme')) {
		$graph_data_array['graph_theme'] = get_selected_theme();
	}

	if (isset($_SESSION[SESS_USER_ID])) {
		$graph_data_array['effective_user'] = $_SESSION[SESS_USER_ID];
	}

	$url  = CACTI_PATH_URL . 'remote_agent.php?action=graph_json';
	$url .= '&local_graph_id=' . get_request_var('local_graph_id');
	$url .= '&rra_id=' . $rra_id;

	foreach ($graph_data_array as $variable => $value) {
		$url .= '&' . $variable . '=' . $value;
	}

	$output = call_remote_data_collector(1, $url);
}

$output = trim($output);
$oarray = array('type' => $gtype, 'local_graph_id' => get_request_var('local_graph_id'), 'rra_id' => $rra_id);

// Check if we received back something populated from rrdtool
if ($output !== false && $output != '' && strpos($output, 'image = ') !== false) {
	// Find the beginning of the image definition row
	$image_begin_pos = strpos($output, 'image = ');

	// Parse and populate everything before the image definition row
	$header_lines = explode("\n", substr($output, 0, $image_begin_pos - 1));

	// Check for additional data points from graphv output
	$graph_start_pos = strpos($output, 'graph_start =', $image_begin_pos);

	if (!$graph_start_pos) {
		// Find the end of the line of the image definition row, after this the raw image data will come
		$image_data_pos = strpos($output, "\n" , $image_begin_pos) + 1;

		// Insert the raw image data to the array
		$oarray['image'] = base64_encode(substr($output, $image_data_pos));
	} else {
		// Find the end of the line of the image definition row, after this the raw image data will come
		$image_data_pos = strpos($output, "\n" , $image_begin_pos) + 1;

		// Insert the raw image data to the array
		$oarray['image'] = base64_encode(substr($output, $image_data_pos, $graph_start_pos - $image_data_pos));

		// Get the datapoints to the end of the file.
		$datapoints_start_pos = strpos($output, 'datapoints =');

		$datapoints = substr($output, $datapoints_start_pos);

		// Get rid of the 'datapoints =' line
		$dp_output = explode("\n", $datapoints);
		unset($dp_output[0]);

		$datapoints = json_decode(implode("\n", $dp_output), true);

		foreach($datapoints as $name => $value) {
			$oarray[$name] = $value;
		}
	}

	foreach ($header_lines as $line) {
		$parts = explode(' = ', $line);
		$oarray[$parts[0]] = trim($parts[1]);
	}

	if (isset($oarray['meta'])) {
		if(isset($oarray['meta']['legend']) & isset($xport_meta['legend'])) {
			foreach ($oarray['meta']['legend'] as $key => $value) {
				$legend = trim(preg_replace( '/[^a-z0-9 _()]/i', '', $value ));
				if($legend) {
					$color = (isset($xport_meta['legend'][$legend])) ? $xport_meta['legend'][$legend] : '';
					$oarray['meta']['legend'][$key] = array('legend' => $legend, 'color' => $color);
				}else{
					unset($oarray['meta']['legend'][$key]);
				}
			}
		}
	}

	$replacement_legend = rrdtool_replacement_legend(get_request_var('local_graph_id'));

	if (cacti_sizeof($replacement_legend) && isset($oarray['meta']['legend'])) {
		$oarray['meta']['legend'] = $replacement_legend;
	}

	/**
	 * remove the unknown data and business hours columns from the
	 * output data as it interferes with the hover output.
	 */
	if (isset($oarray['data']) && isset($xport_meta['ignoreItems'])) {
		$new_data = array();
		foreach($oarray['data'] as $index => $data) {
			for ($i = 1; $i <= $xport_meta['ignoreItems']; $i++) {
				unset($data[$i]);
			}

			$i = 0;
			foreach($data as $value) {
				$new_array[$index][$i] = $value;
				$i++;
			}
		}

		$oarray['data'] = $new_array;
	}
} else {
	/* image type now png */
	$oarray['type'] = 'png';

	ob_start();

	$graph_data_array['get_error'] = true;

	$null_param = array();

	rrdtool_function_graph(get_request_var('local_graph_id'), $rra_id, $graph_data_array, null, $null_param, $_SESSION[SESS_USER_ID]);

	$error = ob_get_contents();

	ob_end_clean();

	if (read_config_option('stats_poller') == '') {
		$error = __('The Cacti Poller has not run yet.');
	}

	if (isset($graph_data_array['graph_width']) && isset($graph_data_array['graph_height'])) {
		$image = rrdtool_create_error_image($error, $graph_data_array['graph_width'], $graph_data_array['graph_height']);
	} else {
		$image = rrdtool_create_error_image($error);
	}

	if (isset($graph_data_array['graph_width'])) {
		if (isset($graph_data_array['graph_nolegend'])) {
			$oarray['image_width']  = round($graph_data_array['graph_width'] * 1.24, 0);
			$oarray['image_height'] = round($graph_data_array['graph_height'] * 1.45, 0);
		} else {
			$oarray['image_width']  = round($graph_data_array['graph_width'] * 1.15, 0);
			$oarray['image_height'] = round($graph_data_array['graph_height'] * 1.8, 0);
		}
	} else {
		$oarray['image_width'] = round(db_fetch_cell_prepared('SELECT width
			FROM graph_templates_graph
			WHERE local_graph_id = ?',
			array(get_request_var('local_graph_id'))), 0);

		$oarray['image_height'] = round(db_fetch_cell_prepared('SELECT height
			FROM graph_templates_graph
			WHERE local_graph_id = ?',
			array(get_request_var('local_graph_id'))), 0);
	}

	if ($image !== false) {
		$oarray['image'] = base64_encode($image);
	} else {
		$oarray['image'] = base64_encode(file_get_contents(__DIR__ . '/images/cacti_error_image.png'));
	}
}

header('Content-Type: application/json');
header('Cache-Control: max-age=15');
$json = json_encode($oarray);
header('Content-Length: ' . strlen($json));
print $json;
