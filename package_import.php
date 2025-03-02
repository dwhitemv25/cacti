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

include('./include/auth.php');
include_once('./lib/import.php');
include_once('./lib/poller.php');
include_once('./lib/template.php');
include_once('./lib/utility.php');
include_once('./lib/xml.php');
include_once('./include/vendor/phpdiff/Diff.php');
include_once('./include/vendor/phpdiff/Renderer/Html/Inline.php');

/* set default action */
set_default_action();

check_tmp_dir();

$actions = array(
	1 => __('Import'),
);

switch(get_request_var('action')) {
	case 'save':
		form_save();

		break;
	case 'actions':
		form_actions();

		break;
	case 'details':
		package_get_details();

		break;
	case 'diff':
		package_diff_file();

		break;
	case 'verify':
		package_verify_key();

		break;
	case 'accept':
		package_accept_key();

		break;
	default:
		top_header();
		package_import();
		bottom_footer();

		break;
}

function check_tmp_dir() {
	if (is_tmp_writable()) {
		return true;
	} else {
		?>
		<script type='text/javascript'>
		var mixedReasonTitle = '<?php print __('Key Generation Required to Use Tool');?>';
		var mixedOnPage      = '<?php print __esc('Packaging Key Information Not Found');?>';

		sessionMessage = {
			message: '<?php print __('In order to use this Packaging Tool, you must first run the <b><i class="deviceUp">genkey.php</i></b> script in the cli directory.  Once that is complete, you will have a public and private key used to sign your packages.');?>',
			level: MESSAGE_LEVEL_MIXED
		};

		$(function() {
			displayMessages();
		});
		</script>
		<?php

		exit;
	}
}

function form_actions() {
	global $config, $actions;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z0-9_]+)$/')));
	get_filter_request_var('data_source_profile');
	get_filter_request_var('package_location');
	get_filter_request_var('remove_orphans', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/(on)/')));
	get_filter_request_var('replace_svalues', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/(on)/')));
	/* ==================================================== */

	$package_location = get_filter_request_var('package_location');
	$profile_id       = get_filter_request_var('data_source_profile');
	$remove_orphans   = isset_request_var('remove_orphans') ? true:false;
	$replace_svalues  = isset_request_var('replace_svalues') ? true:false;
	$preview          = false;

	$package = json_decode(get_repo_manifest_file($package_location), true);

	$manifest = $package['manifest'];

	// Import Execution
	if (isset_request_var('selected_items')) {
		$selected_items  = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		$hashes = unserialize(stripslashes(get_nfilter_request_var('selected_hashes')), array('allowed_classes' => false));
		$files  = unserialize(stripslashes(get_nfilter_request_var('selected_files')), array('allowed_classes' => false));

		$import_packages = array();
		$import_hashes   = array();
		$import_files    = array();
		$import_names    = array();

		if (cacti_sizeof($hashes)) {
			foreach($hashes as $h) {
				if (is_array($h['files'])) {
					foreach($h['files'] as $file) {
						$import_hashes[$file][] = $h['hash'];
						$import_packages[$file] = $file;
					}
				} else {
					$import_hashes[$h['files']][] = $h['files'];
					$import_packages[$h['files']] = $h['files'];
				}
			}
		}

		if (cacti_sizeof($files)) {
			foreach($files as $f) {
				$import_files[$f['filename']][]  = $f['pfile'];
				$import_packages[$f['filename']] = $f['filename'];
			}
		}

		if (cacti_sizeof($import_packages)) {
			foreach($import_packages as $filename) {
				if (!isset($import_names[$filename])) {
					foreach($manifest as $index => $item) {
						if ($item['filename'] == $filename) {
							$name = $item['name'];

							$import_names[$filename] = $name;

							break;
						}
					}
				} else {
					$name = $import_names[$filename];
				}

				if (get_request_var('drp_action') == 1) { // Import
					$data = get_repo_file($package_location, $filename);

					if (isset($import_hashes[$filename]) && cacti_sizeof($import_hashes[$filename])) {
						$hashes = $import_hashes[$filename];
					} else {
						$hashes = array();
					}

					if (isset($import_files[$filename]) && cacti_sizeof($import_files[$filename])) {
						$files = $import_files[$filename];
					} else {
						$files = array();
					}

					if ($data !== false) {
						$tmp_dir = sys_get_temp_dir() . '/package' . $_SESSION[SESS_USER_ID];

						if (!is_dir($tmp_dir)) {
							if (!mkdir($tmp_dir, true)) {
								raise_message('tmpdir_fail', __('Unable to create package temporary directory %s.', $tmp_dir), MESSAGE_LEVEL_ERROR);
								return false;
							}
						}

						$xmlfile = $tmp_dir . '/' . $filename;

						file_put_contents($xmlfile, $data);

						$data = import_package($xmlfile, $profile_id, $remove_orphans, $replace_svalues, $preview, false, true, $hashes, $files);

						if ($data !== false && cacti_sizeof($data[0])) {
							import_display_results($data[0], array(), true, true);
							raise_message('import_success_' . md5($xmlfile), __('The Package %s Imported Successfully', $name), MESSAGE_LEVEL_INFO);
						} else {
							raise_message('import_fail_' . md5($xmlfile), __('The Package %s Import Failed', $name), MESSAGE_LEVEL_ERROR);
						}

						unlink($xmlfile);
					}
				}
			}
		}

		header('Location: package_import.php?package_location=' . $package_location);
		exit;
	}

	// Import Confirm
	$pkg_list      = '';
	$pkg_array     = array();
	$skp_list      = '';
	$skp_array     = array();

	$pkg_import_list  = '';
	$pkg_import_array = array();
	$found_pkg_array  = array();

	$pkg_file_list        = '';
	$pkg_file_array       = array();
	$found_pkg_file_array = array();

	/* loop through each of the graphs selected on the previous page and get more info about them */
	foreach ($_POST as $var => $val) {
		if (strpos($var, 'chk_file_') !== false) {
			$id = base64_decode(str_replace('chk_file_', '', $var), true);
			$id = json_decode($id, true);

			// Get rid of the basename
			$id['pfile'] = str_replace(CACTI_PATH_BASE . '/', '', $id['pfile']);

			$pkg_file_list .= '<tr>' .
				'<td style="width:50%">' . html_escape($id['package']) . '</td>' .
				'<td style="width:50%">' . html_escape($id['pfile'])   . '</td>' .
			'</td>';

			$pkg_file_array[] = $id;

			$found_pkg_file_array[$id['package']] = $id['package'];
		}

		if (strpos($var, 'chk_import_') !== false) {
			$id = base64_decode(str_replace('chk_import_', '', $var), true);
			$id = json_decode($id, true);

			$packages = explode('<br>', $id['package']);
			$package  = '';
			foreach($packages as $index => $p) {
				$package .= ($index > 0 ? ', ':'') . html_escape($p);

				$found_pkg_array[$p] = $p;
			}

			$statuses = explode('<br>', $id['status']);
			$status   = '';
			foreach($statuses as $index => $s) {
				$status .= ($index > 0 ? ', ':'') . html_escape(ucfirst($s));
			}

			$pkg_import_list .= '<tr>' .
				'<td style="width:40%">' . $package                 . '</td>' .
				'<td style="width:20%">' . html_escape($id['type']) . '</td>' .
				'<td style="width:20%">' . html_escape($id['name']) . '</td>' .
				'<td style="width:20%">' . $status                  . '</td>' .
			'</td>';

			$pkg_import_array[] = $id;
		}
	}

	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_package_([0-9|]+)$/', $var, $matches)) {
			$package = $manifest[$matches[1]]['name'];

			if (isset($found_pkg_array[$package]) || isset($found_pkg_file_array[$package])) {
				$pkg_list .= '<li>' . html_escape($package) . '</li>';
				$pkg_array[] = $matches[1];
			} else {
				$skp_list .= '<li>' . html_escape($package) . '</li>';
				$skp_array[] = $matches[1];
			}
		}
	}

	top_header();

	form_start('package_import.php');

	html_start_box(__('Package %s', $actions[get_nfilter_request_var('drp_action')]), '60%', '', '3', 'center', '');

	if (isset($pkg_array) && cacti_sizeof($pkg_array)) {
		if (get_nfilter_request_var('drp_action') == '1') { /* import */
			if ($pkg_file_list != '' || $pkg_import_list != '') {
				print "<tr>
					<td class='textArea'>
						<p>" . __n('Click \'Continue\' to Import the following Package.', 'Click \'Continue\' to Import all following Packages.', cacti_sizeof($pkg_array)) . "</p>
						<div class='itemlist'><ul>$pkg_list</ul></div>
					</td>
				</tr>";

				if ($skp_list != '') {
					print "<tr>
						<td class='textArea'>
							<p>" . __n(
								'The following Selected Package will be skipped as no Files or Template Items were selected.',
								'The following selected Packages will be skipped as no Files or Template Items were selected.', cacti_sizeof($skp_array)) . "</p>
							<div class='itemlist'><ul>$skp_list</ul></div>
						</td>
					</tr>";
				}

				if ($pkg_file_list != '') {
					print '<tr><td><div class="cactiTableTitleRow">' . __('Files to be Imported') . '</div></td></tr>';

					print "<tr>
						<td class='textArea'>
							<p>" . __('The Files in the list below will be imported.') . "</p>
							<table class='cactiTable itemlist'>
								<tr class='tableHeader'>
									<th>" . __('Package') . "</th>
									<th>" . __('File')     . "</th>
								</tr>
								$pkg_file_list
							</table>
						</td>
					</tr>";
				}

				if ($pkg_import_list != '') {
					print '<tr><td><div class="cactiTableTitleRow">' . __('Template Items to be Imported') . '</div></td></tr>';

					print "<tr>
						<td class='textArea'>
							<p>" . __('The Template Items in the list below will be imported.') . "</p>
							<table class='cactiTable itemlist'>
								<tr class='tableHeader'>
									<th>" . __('Package') . "</th>
									<th>" . __('Type')     . "</th>
									<th>" . __('Name')     . "</th>
									<th>" . __('Status')   . "</th>
								</tr>
								$pkg_import_list
							</table>
						</td>
					</tr>";
				}

				$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget' value='" . __esc('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' title='" . __n('Import Package', 'Import Packages', cacti_sizeof($pkg_array)) . "'>";
			} else {
				raise_message('no_selection', __('You must select either a File or a Template Item to import before proceeding'), MESSAGE_LEVEL_ERROR);
				header('Location: package_import.php');
				exit;
			}
		}
	} else {
		raise_message(40);
		header('Location: package_import.php');
		exit;
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='package_location' value='" . get_request_var('package_location') . "'>
			<input type='hidden' name='data_source_profile' value='" . get_request_var('data_source_profile') . "'>
			<input type='hidden' name='remove_orphans' value='" . (isset_request_var('remove_orphans') ? 'on':'') . "'>
			<input type='hidden' name='replace_svalues' value='" . (isset_request_var('replace_svalues') ? 'on':'') . "'>
			<input type='hidden' name='selected_items' value='" . (isset($pkg_array) ? serialize($pkg_array) : '') . "'>
			<input type='hidden' name='selected_hashes' value='" . (isset($pkg_import_array) ? serialize($pkg_import_array) : '') . "'>
			<input type='hidden' name='selected_files' value='" . (isset($pkg_file_array) ? serialize($pkg_file_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . html_escape(get_nfilter_request_var('drp_action')) . "'>
			$save_html
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

function form_save() {
	global $config, $preview_only;

	validate_request_vars();

	if (isset_request_var('save_component_import')) {
		if (isset($_FILES['import_file']['tmp_name']) &&
			($_FILES['import_file']['tmp_name'] != 'none') &&
			($_FILES['import_file']['tmp_name'] != '')) {
			/* file upload */
			$xmlfile = $_FILES['import_file']['tmp_name'];

			$_SESSION['sess_import_package'] = file_get_contents($xmlfile);
		} elseif (isset($_SESSION['sess_import_package'])) {
			$xmlfile = sys_get_temp_dir() . '/package_import_' . rand();

			file_put_contents($xmlfile, $_SESSION['sess_import_package']);

			unset($_SESSION['sess_import_package']);
		} else {
			header('Location: package_import.php');
			exit;
		}

		if (isset_request_var('trust_signer') && get_nfilter_request_var('trust_signer') == 'on') {
			import_validate_public_key($xmlfile, true);
		}

		if (get_filter_request_var('data_source_profile') == '0') {
			$import_as_new = true;
			$profile_id = db_fetch_cell('SELECT id FROM data_source_profiles ORDER BY `default` DESC LIMIT 1');
		} else {
			$import_as_new = false;
			$profile_id = get_request_var('data_source_profile');
		}

		if (get_nfilter_request_var('preview_only') == 'on') {
			$preview_only = true;
		} elseif (get_nfilter_request_var('import_confirmed') == 'on') {
			$preview_only = false;
		} else {
			$preview_only = true;
		}

		if (isset_request_var('remove_orphans') && get_nfilter_request_var('remove_orphans') == 'on') {
			$remove_orphans = true;
		} else {
			$remove_orphans = false;
		}

		if (isset_request_var('replace_svalues') && get_nfilter_request_var('replace_svalues') == 'on') {
			$replace_svalues = true;
		} else {
			$replace_svalues = false;
		}

		$hashes = array();
		$files  = array();

		/* loop through each of the graphs selected on the previous page and get more info about them */
		foreach ($_POST as $var => $val) {
			if (strpos($var, 'chk_file_') !== false) {
				$id = base64_decode(str_replace('chk_file_', '', $var), true);
				$id = json_decode($id, true);

				if (strpos($id['pfile'], '/') !== false) {
					$parts = explode('/', $id['pfile']);
				} elseif (strpos($id['pfile'], '\\') !== false) {
					$parts = explode('\\', $id['pfile']);
				} else {
					$parts = array($id['pfile']);
				}

				foreach ($parts as $index => $p) {
					if ($p == 'scripts') {
						break;
					}

					if ($p == 'resource') {
						break;
					} else {
						unset($parts[$index]);
					}
				}

				$id['pfile'] = implode('/', $parts);

				$files[] = $id['pfile'];
			}

			if (strpos($var, 'chk_import_') !== false) {
				$id = base64_decode(str_replace('chk_import_', '', $var), true);
				$id = json_decode($id, true);

				$hashes[] = $id['hash'];
			}
		}

		if (cacti_sizeof($files) && !cacti_sizeof($hashes)) {
			$hashes[] = 'dont import';
		} elseif (cacti_sizeof($hashes) && !cacti_sizeof($files)) {
			$files[]  = 'dont import';
		}

		if (file_exists($xmlfile)) {
			cacti_log("Importing $xmlfile");
		} else {
			cacti_log("Unable to import $xmlfile");
		}

		$package_name = import_package_get_name($xmlfile);

		cacti_log('Package name is ' . $package_name);

		/* obtain debug information if it's set */
		$data = import_package($xmlfile, $profile_id, $remove_orphans, $replace_svalues, $preview_only, false, false, $hashes, $files);

		if ($preview_only) {
			package_prepare_import_array($templates, $files, $package_name, $xmlfile, $data);

			import_display_package_data($templates, $files, $package_name, $xmlfile, $data, false);
		} else {
			if ($data !== false) {
				raise_message('import_success_' . md5($xmlfile), __('The Package %s Imported Successfully', $package_name), MESSAGE_LEVEL_INFO);
			} else {
				raise_message('import_fail_' . md5($xmlfile), __('The Package %s Import Failed', $package_name), MESSAGE_LEVEL_ERROR);
			}

			unlink($xmlfile);

			unset($_SESSION['sess_import_package']);

			header('Location: package_import.php?package_location=0');
			exit;
		}
	}
}

function package_file_get_contents($package_location, $package_file, $filename) {
	if ($package_location > 0) {
		$repo = json_decode(get_repo_manifest_file($package_location), true);

		$manifest = $repo['manifest'];

		$package_found = false;

		if (cacti_sizeof($manifest)) {
			foreach($manifest as $m) {
				if ($m['filename'] == $package_file) {
					$author   = $m['metadata']['author'];
					$homepage = $m['metadata']['homepage'];
					$email    = $m['metadata']['email'];

					$package_found = true;

					break;
				}
			}
		}
	} elseif (isset($_SESSION['sess_import_package'])) {
		$xmlfile = sys_get_temp_dir() . '/package_import_' . rand();

		file_put_contents($xmlfile, $_SESSION['sess_import_package']);

		$data = import_read_package_data($xmlfile, $binary_signature, true);

		$public_key = import_package_get_public_key($xmlfile);

		$fdata = false;

		foreach ($data['files']['file'] as $file) {
			if ($file['name'] == $filename) {
				$binary_signature = base64_decode($file['filesignature'], true);

				$fdata = base64_decode($file['data'], true);

				/* provide two checks against the public key */
				$ok = openssl_verify($fdata, $binary_signature, $public_key, OPENSSL_ALGO_SHA1);

				if ($ok != 1) {
					$ok = openssl_verify($fdata, $binary_signature, $public_key, OPENSSL_ALGO_SHA256);
				}

				if ($ok != 1) {
					$fdata = false;
				}

				break;
			}
		}

		unlink($xmlfile);

		return $fdata;
	}

	if ($package_found) {
		$data = get_repo_file($package_location, $package_file, true);

		if ($data !== false) {
			$tmp_dir = sys_get_temp_dir() . '/package' . $_SESSION[SESS_USER_ID];

			if (!is_dir($tmp_dir)) {
				mkdir($tmp_dir);
			}

			$xmlfile = $tmp_dir . '/' . $package_file;

			file_put_contents($xmlfile, $data);

			$public_key = import_package_get_public_key($xmlfile);

			$data = import_read_package_data($xmlfile, $binary_signature, true);

			$fdata = false;

			foreach ($data['files']['file'] as $file) {
				if ($file['name'] == $filename) {
					$binary_signature = base64_decode($file['filesignature'], true);

					$fdata = base64_decode($file['data'], true);

					/* provide two checks against the public key */
					$ok = openssl_verify($fdata, $binary_signature, $public_key, OPENSSL_ALGO_SHA1);

					if ($ok != 1) {
						$ok = openssl_verify($fdata, $binary_signature, $public_key, OPENSSL_ALGO_SHA256);
					}

					if ($ok != 1) {
						$fdata = false;
					}

					break;
				}
			}

			unlink($xmlfile);

			return $fdata;
		}
	}

	return false;
}

function package_diff_file() {
	global $config;

	$package_location = get_filter_request_var('package_location');
	$package_file     = get_request_var('package_file');
	$filename         = get_request_var('filename');

	$options = array(
		'ignoreWhitespace' => true,
		'ignoreCase' => false
	);

	$newfile = package_file_get_contents($package_location, $package_file, $filename);

	if ($newfile !== false) {
		$newfile = str_replace("\n\r", "\n", $newfile);
		$newfile = explode("\n", $newfile);
	}

	$oldfile = file_get_contents(CACTI_PATH_BASE . '/' . $filename);

	if ($oldfile !== false) {
		$oldfile = str_replace("\n\r", "\n", $oldfile);
		$oldfile = explode("\n", $oldfile);
	}

	if (cacti_sizeof($oldfile)) {
		if (cacti_sizeof($newfile)) {
			$diff = new Diff($oldfile, $newfile, $options);

			$renderer = new Diff_Renderer_Html_Inline;

			print '<body>' . $diff->render($renderer) . '</body></html>';
		} else {
			print "New file does not exist";
		}
	} else {
		print "Old file does not exist";
	}
}

function package_verify_key() {
	$package_location = get_filter_request_var('package_location');

	$failed = array();

	if ($package_location > 0) {
		$package_ids = get_filter_request_var('package_ids', FILTER_VALIDATE_IS_NUMERIC_LIST);

		$repo = json_decode(get_repo_manifest_file($package_location), true);

		$manifest = $repo['manifest'];

		if ($package_ids != '') {
			$package_ids = explode(',', $package_ids);

			foreach($package_ids as $package_id) {
				$filename     = $manifest[$package_id]['filename'];
				$package_name = $manifest[$package_id]['name'];

				$data = get_repo_file($package_location, $filename, true);

				if ($data !== false) {
					$tmp_dir = sys_get_temp_dir() . '/package' . $_SESSION[SESS_USER_ID];

					if (!is_dir($tmp_dir)) {
						mkdir($tmp_dir);
					}

					$xmlfile = $tmp_dir . '/' . $filename;

					file_put_contents($xmlfile, $data);

					$info = import_validate_signature($xmlfile);

					if ($info === false || $info['valid'] === false) {
						$failed[$package_name] = $info;
					}

					unlink($xmlfile);
				} else {
					print json_encode(
						array(
							'title'   => __('Repo File Missing or Damaged'),
							'message' => __('The Repo \'%s\' is NOT Reachable at the URL Location in the package.manifest.', $package_name),
							'header'  => __('Something is wrong with your Package Repository'),
							'status'  => 'fileerror'
						)
					);

					exit;
				}
			}
		}
	} elseif (isset($_SESSION['sess_import_package'])) {
		$xmlfile = sys_get_temp_dir() . '/package_import_' . rand();

		file_put_contents($xmlfile, $_SESSION['sess_import_package']);
		$vsig  = import_validate_signature($xmlfile);

		if ($vsig === false || empty($vsig['valid'])) {
			$failed[$vsig['name']] = $vsig;
		}

		unlink($xmlfile);
	}

	if (cacti_sizeof($failed)) {
		$message = __('There are Signature Trust issues.<br>');
		$authors  = array();
		$packages = array();

		foreach($failed as $package) {
			$packages[] = $package['name'];

			$authors[$package['author']] = $package['email'];
		}

		foreach($authors as $author => $email) {
			$message .= ($message != '' ? '<br>':'') . __('<b>Author:</b> &lt;%s&gt; %s.<br>', $package['author'], $package['email']);
		}

		foreach($packages as $package) {
			$message .= ($message != '' ? '<br>':'') . __('<b>Package:</b> %s', $package);
		}

		$message .= '<br><br>' . __('Press \'Ok\' to start Trusting the Signer.  Press \'Cancel\' or hit escape to Cancel.');

		print json_encode(
			array(
				'title'   => __('Some Packages Not Trusted'),
				'message' => $message,
				'status'  => 'error'
			)
		);
	} else {
		print json_encode(
			array(
				'title'   => __('All Packages Trusted'),
				'message' => __('All Package Signatures Validated'),
				'status'  => 'success'
			)
		);
	}
}

function package_accept_key() {
	$package_location = get_filter_request_var('package_location');

	if ($package_location > 0) {
		$package_ids = get_filter_request_var('package_ids', FILTER_VALIDATE_IS_NUMERIC_LIST);

		$repo = json_decode(get_repo_manifest_file($package_location), true);

		$manifest = $repo['manifest'];

		$failed = array();

		if ($package_ids != '') {
			$package_ids = explode(',', $package_ids);

			foreach($package_ids as $package_id) {
				$filename     = $manifest[$package_id]['filename'];
				$package_name = $manifest[$package_id]['name'];

				$data = get_repo_file($package_location, $filename, true);

				if ($data !== false) {
					$tmp_dir = sys_get_temp_dir() . '/package' . $_SESSION[SESS_USER_ID];

					if (!is_dir($tmp_dir)) {
						mkdir($tmp_dir);
					}

					$xmlfile = $tmp_dir . '/' . $filename;

					file_put_contents($xmlfile, $data);

					import_validate_public_key($xmlfile, true);

					unlink($xmlfile);
				} else {
					raise_message('repo_missing', __('The Repo \'%s\' is NOT Reachable at the URL Location or the package.manifest file is missing.', $repo['name']), MESSAGE_LEVEL_WARN);
					header('Location: package_import.php');
					exit;
				}
			}
		}
	} elseif (isset($_SESSION['sess_import_package'])) {
		$xmlfile = sys_get_temp_dir() . '/package_import_' . rand();

		file_put_contents($xmlfile, $_SESSION['sess_import_package']);

		import_validate_public_key($xmlfile, true);

		unlink($xmlfile);
	}
}

function package_get_details() {
	$package_ids      = get_filter_request_var('package_ids', FILTER_VALIDATE_IS_NUMERIC_LIST);
	$package_location = get_filter_request_var('package_location');
	$profile_id       = get_filter_request_var('data_source_profile');
	$remove_orphans   = isset_request_var('remove_orphans') ? true:false;
	$replace_svalues  = isset_request_var('replace_svalues') ? true:false;
	$preview          = true;

	$repo = json_decode(get_repo_manifest_file($package_location), true);

	$manifest = $repo['manifest'];

	if ($package_ids != '') {
		$package_ids = explode(',', $package_ids);

		$templates = array();
		$files     = array();

		foreach($package_ids as $package_id) {
			$filename     = $manifest[$package_id]['filename'];
			$package_name = $manifest[$package_id]['name'];

			$data = get_repo_file($package_location, $filename, false);

			if ($data !== false) {
				$tmp_dir = sys_get_temp_dir() . '/package' . $_SESSION[SESS_USER_ID];

				if (!is_dir($tmp_dir)) {
					mkdir($tmp_dir);
				}

				$xmlfile = $tmp_dir . '/' . $filename;

				file_put_contents($xmlfile, $data);

				$validated = import_validate_public_key($xmlfile, false);

				if ($validated === false) {
					$public_key = get_public_key();
				} else {
					$public_key = $validated;
				}

				$data = import_package($xmlfile, $profile_id, $remove_orphans, $replace_svalues, $preview);

				package_prepare_import_array($templates, $files, $package_name, $filename, $data);

				unlink($xmlfile);
			} else {
				raise_message_javascript(__('Error in Package'), __('The package "%s" download or validation failed', $package_name), __('See the cacti.log for more information.  It could be that you had either an API Key error or the package was tamered with, or the location is not available.'));
			}
		}

		import_display_package_data($templates, $files, $package_name, $filename, $data);
	} else {
		raise_message_javascript(__('Error in Package'), __('The package download or validation failed'), __('See the cacti.log for more information.  It could be that you had either an API Key error or the package was tamered with, or the location is not available'));
	}
}

function import_validate_public_key($xmlfile, $accept = false) {
	$public_key1 = get_public_key_sha1();
	$public_key2 = get_public_key_sha256();

	$info = import_get_package_info($xmlfile);

	if ($info !== false) {
		if ($info['pubkey'] != '') {
			$insert = false;

			$accepted = db_fetch_cell_prepared('SELECT COUNT(*)
				FROM package_public_keys
				WHERE public_key = ?',
				array($info['pubkey']));

			if ($accepted) {
				return $info['pubkey'];
			} elseif ($public_key1 == $info['pubkey']) {
				$insert = true;
			} elseif ($public_key2 == $info['pubkey']) {
				$insert = true;
			} elseif ($accept) {
				$insert = true;
			}

			if ($insert) {
				db_execute_prepared('INSERT IGNORE INTO package_public_keys
					(md5sum, author, homepage, email_address, public_key)
					VALUES(?, ?, ?, ?, ?)',
					array(md5($info['pubkey']), $info['author'], $info['homepage'], $info['email'], $info['pubkey']));

				return $info['pubkey'];
			}
		} else {
			raise_message_javascript(__('Error in Package'), __('Package XML File Damaged.'), __('The XML files appears to be invalid and does not contain a public key.  Please contact the package author to obtain a revised package.'));
		}
	} else {
		raise_message_javascript(__('Error in Package'), __('The XML files for the package does not exist'), __('Check the package repository file for files that should exist and find the one that is missing'));
	}

	return false;
}

function import_display_package_data($templates, $files, $package_name, $filename, $data, $multipackage = true) {
	global $config, $device_classes;

	if (!$multipackage) {
		$details = import_package_get_details($filename);

		html_start_box(__('Packages Details'), '100%', '', '1', 'center', '');

		$display_text = array(
			array(
				'display' => __('Device Class')
			),
			array(
				'display' => __('Version'),
			),
			array(
				'display' => __('Copyright')
			),
			array(
				'display' => __('Author'),
			),
			array(
				'display' => __('Email')
			),
			array(
				'display' => __('Homepage'),
			),
		);

		html_header($display_text);

		$id = 99;

		form_alternate_row('line_' . $id);

		if (isset($details['class']) && array_key_exists($details['class'], $device_classes)) {
			form_selectable_cell($device_classes[$details['class']], $id);
		} else {
			form_selectable_cell(__('Unknown'), $id);
		}

		form_selectable_ecell($details['version'], $id, '', 'center');
		form_selectable_ecell($details['copyright'], $id);

		form_selectable_ecell($details['author'], $id);
		form_selectable_ecell($details['email'], $id);
		form_selectable_cell($details['homepage'], $id);

		form_end_row();

		html_end_box();
	}

	// Show the filename status'
	if (cacti_sizeof($files)) {
		html_start_box(__('Import Package Filenames [ None selected imports all, Check to import selectively ]'), '100%', '', '1', 'center', '');

		$display_text = array(
			array(
				'display' => __('Package'),
			),
			array(
				'display' => __('Filename'),
			),
			array(
				'display' => __('Status')
			)
		);

		html_header_checkbox($display_text, false, '', true, 'file');

		foreach($files as $pdata => $pfiles) {
			list($file_package_file, $file_package_name) = explode('|', $pdata);

			foreach($pfiles as $pfile => $status) {
				$id = 'file_' . base64_encode(
					json_encode(
						array(
							'package'  => $file_package_name,
							'filename' => $file_package_file,
							'pfile'    => $pfile,
						)
					)
				);

				form_alternate_row('line_' . $id);
				form_selectable_ecell($file_package_name, $id);
				form_selectable_ecell($pfile, $id);

				$status  = explode(',', $status);
				$nstatus = '';

				foreach($status as $s) {
					$s = trim($s);

					if ($s == 'differences') {
						$url = 'package_import.php' .
							'?action=diff' .
							'&package_location=' . get_request_var('package_location') .
							'&package_file=' . $file_package_file .
							'&package_name=' . $file_package_name .
							'&filename=' . str_replace(CACTI_PATH_BASE . '/', '', $pfile);

						$nstatus .= ($nstatus != '' ? ', ':'') .
							"<a class='diffme linkEditMain' href='" . html_escape($url) . "'>" . __('Differences') . '</a>';
					} elseif ($s == 'identical') {
						$nstatus .= ($nstatus != '' ? ', ':'') . __('Unchanged');
					} elseif ($s == 'not writable') {
						$nstatus .= ($nstatus != '' ? ', ':'') . __('Not Writable');
					} elseif ($s == 'writable') {
						$nstatus .= ($nstatus != '' ? ', ':'') . __('Writable');
					} elseif ($s == 'new') {
						$nstatus .= ($nstatus != '' ? ', ':'') . __('New');
					} else {
						$nstatus .= ($nstatus != '' ? ', ':'') . __('Unknown');
					}
				}

				form_selectable_cell($nstatus, $id);

				form_checkbox_cell($pfile, $id);

				form_end_row();
			}
		}

		html_end_box();
	}

	if (cacti_sizeof($templates)) {
		html_start_box(__('Import Package Templates [ None selected imports all, Check to import selectively ]'), '100%', '', '1', 'center', '');

		if ($multipackage) {
			$display_text = array(
				array(
					'display' => __('Packages'),
				),
				array(
					'display' => __('Template Type')
				),
				array(
					'display' => __('Template Name')
				),
				array(
					'display' => __('Status')
				),
				array(
					'display' => __('Changes/Diffferences')
				)
			);
		} else {
			$display_text = array(
				array(
					'display' => __('Template Type')
				),
				array(
					'display' => __('Template Name')
				),
				array(
					'display' => __('Status')
				),
				array(
					'display' => __('Changes/Diffferences')
				)
			);
		}

		html_header_checkbox($display_text, false, '', true, 'import');

		$templates = array_reverse($templates);

		foreach($templates as $hash => $detail) {
			$files = explode('<br>', $detail['package_file']);

			$id = 'import_' . base64_encode(
				json_encode(
					array(
						'package' => $detail['package'],
						'hash'    => $hash,
						'type'    => $detail['type_name'],
						'name'    => $detail['name'],
						'status'  => $detail['status'],
						'files'   => $files
					)
				)
			);

			if ($detail['status'] == 'updated') {
				$status = "<span class='updateObject'>" . __('Updated') . '</span>';
			} elseif ($detail['status'] == 'new') {
				$status = "<span class='newObject'>" . __('New') . '</span>';
			} else {
				$status = "<span class='deviceUp'>" . __('Unchanged') . '</span>';
			}

			form_alternate_row('line_' . $id);

			if ($multipackage) {
				form_selectable_ecell($detail['package'], $id);
			}

			form_selectable_ecell($detail['type_name'], $id);
			form_selectable_ecell($detail['name'], $id);
			form_selectable_cell($status, $id);

			if (isset($detail['vals'])) {
				$diff_details = '';
				$diff_array   = array();
				$orphan_array = array();

				foreach($detail['vals'] as $package => $diffs) {
					if (isset($diffs['differences'])) {
						foreach($diffs['differences'] as $item) {
							$diff_array[$item] = $item;
						}
					}

					if (isset($diffs['orphans'])) {
						foreach($diffs['orphans'] as $item) {
							$orphan_array[$item] = $item;
						}
					}
				}

				if (cacti_sizeof($diff_array)) {
					$diff_details .= __('Differences') . '<br>' . implode('<br>', $diff_array);
				}

				if (cacti_sizeof($orphan_array)) {
					$diff_details .= ($diff_details != '' ? '<br>':'') . __('Orphans') . '<br>' . implode('<br>', $orphan_array);
				}

				form_selectable_cell($diff_details, $id, '', 'white-space:pre-wrap');
			} else {
				form_selectable_cell(__('None'), $id);
			}

			form_checkbox_cell($detail['name'], $id);

			form_end_row();
		}

		html_end_box();
	}

	?>
	<script type='text/javascript'>

	function getURLVariable(url, varname) {
		var urlparts = url.slice(url.indexOf('?') + 1).split('&');

		for (var i = 0; i < urlparts.length; i++) {
			var urlvar = urlparts[i].split('=');

			if (urlvar[0] == varname) {
				return urlvar[1];
			}
		}

		return null;
	}

	$(function() {
		if ($('#package_import_details2_child').length) {
			applySelectorVisibilityAndActions();

			$('#package_import_details2_child').find('tr[id^="line_import_new_"]').each(function(event) {
				selectUpdateRow(event, $(this));
			});

			makePackagesClickable();
		}

		$('.diffme').off('click').on('click', function(event) {
			event.preventDefault();

			var url = $(this).attr('href');

			$.get(url, function(data) {
				$('#dialog').html(data);

				var package_name = getURLVariable(url, 'package_name');
				var filename     = getURLVariable(url, 'filename');

				$('#dialog').dialog({
					autoOpen: true,
					title: '<?php print __('File Differences for: ');?>' + filename,
					width: '60%',
					maxWidth: '90%',
					maxHeight: 600
				});
			});
		});
	});
	<?php
}

function validate_request_vars() {
	$default_profile = get_default_profile();

	/* ================= input validation and session storage ================= */
	$filters = array(
		'replace_svalues' => array(
			'filter' => FILTER_VALIDATE_REGEXP,
			'options' => array('options' => array('regexp' => '(on|true|false)')),
			'default' => read_config_option('replace_svalues')
		),
		'remove_orphans' => array(
			'filter' => FILTER_VALIDATE_REGEXP,
			'options' => array('options' => array('regexp' => '(on|true|false)')),
			'default' => read_config_option('remove_orphans')
		),
		'trust_signer' => array(
			'filter' => FILTER_VALIDATE_REGEXP,
			'options' => array('options' => array('regexp' => '(on|true|false)')),
			'default' => read_config_option('trust_signer')
		),
		'package_location' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => read_config_option('package_location')
		),
		'data_source_profile' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => $default_profile
		),
		'image_format' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => read_config_option('default_image_format')
		),
		'graph_width' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => read_config_option('default_graph_width')
		),
		'graph_height' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => read_config_option('default_graph_height')
		),
	);

	validate_store_request_vars($filters, 'sess_pimport');
	/* ================= input validation ================= */
}

function get_import_form($repo_id, $default_profile) {
	global $image_types;

	validate_request_vars();

	if (isset_request_var('preview_only') && get_nfilter_request_var('preview_only') == 'on') {
		$preview_only = 'on';
	} else {
		$preview_only = '';
	}

	if (isset_request_var('replace_svalues') && get_nfilter_request_var('replace_svalues') == 'on') {
		$replace_svalues = 'on';
	} else {
		$replace_svalues = '';
	}

	if (isset_request_var('remove_orphans') && get_nfilter_request_var('remove_orphans') == 'on') {
		$remove_orphans = 'on';
	} else {
		$remove_orphans = '';
	}

	if (isset_request_var('trust_signer') && get_nfilter_request_var('trust_signer') == 'on') {
		$trust_signer = 'on';
	} else {
		$trust_signer = '';
	}

	if (isset_request_var('image_format')) {
		$image_format = get_filter_request_var('image_format');
	} else {
		$image_format = read_config_option('default_image_format');
	}

	if (isset_request_var('graph_width')) {
		$graph_width = get_filter_request_var('graph_width');
	} else {
		$graph_width = read_config_option('default_graph_width');
	}

	if (isset_request_var('graph_height')) {
		$graph_height = get_filter_request_var('graph_height');
	} else {
		$graph_height = read_config_option('default_graph_height');
	}

	$form = array(
		'import_file' => array(
			'friendly_name' => __('Local Package Import File'),
			'description'   => __('The *.xml.gz file located on your Local machine to Upload and Import.'),
			'accept'        => '.xml.gz',
			'method'        => 'file'
		),
		'trust_header' => array(
			'friendly_name' => __('Package Signature'),
			'collapsible'   => 'true',
			'method'        => 'spacer',
		),
		'trust_signer' => array(
			'friendly_name' => __('Automatically Trust Signer'),
			'description'   => __('If checked, Cacti will automatically Trust the Signer for this and any future Packages by that author.'),
			'method'        => 'checkbox',
			'value'         => $trust_signer,
			'default'       => ''
		),
	);

	$form2 = array(
		'data_header' => array(
			'friendly_name' => __('Data Source Overrides'),
			'collapsible'   => 'true',
			'method'        => 'spacer',
		),
		'data_source_profile' => array(
			'friendly_name' => __('Data Source Profile'),
			'description'   => __('Select the Data Source Profile.  The Data Source Profile controls polling interval, the data aggregation, and retention policy for the resulting Data Sources.'),
			'method'        => 'drop_sql',
			'sql'           => "SELECT id, name FROM data_source_profiles ORDER BY name",
			'none_value'    => __('Create New from Template'),
			'value'         => isset_request_var('import_dsp') ? get_filter_request_var('import_dsp'):'',
			'default'       => $default_profile
		),
		'graph_header' => array(
			'friendly_name' => __('Graph/Data Template Overrides'),
			'collapsible'   => 'true',
			'method'        => 'spacer',
		),
		'remove_orphans' => array(
			'friendly_name' => __('Remove Orphaned Graph Items'),
			'description'   => __('If checked, Cacti will delete any Graph Items from both the Graph Template and associated Graphs that are not included in the imported Graph Template.'),
			'method'        => 'checkbox',
			'value'         => $remove_orphans,
			'default'       => ''
		),
		'replace_svalues' => array(
			'friendly_name' => __('Replace Suggested Value Patterns'),
			'description'   => __('Replace Data Source and Graph Template Suggested Value Records.  Graphs and Data Sources will take on new names after either a Data Query Reindex or by using the forced Replace Suggested Values process.'),
			'method'        => 'checkbox',
			'value'         => $replace_svalues,
			'default'       => ''
		),
		'image_format' => array(
			'friendly_name' => __('Graph Template Image Format'),
			'description'   => __('The Image Format to be used when importing or updating Graph Templates.'),
			'method'        => 'drop_array',
			'default'       => read_config_option('default_image_format'),
			'value'         => $image_format,
			'array'         => $image_types,
		),
		'graph_height' => array(
			'friendly_name' => __('Graph Template Height', 'pagkage'),
			'description'   => __('The Height to be used when importing or updating Graph Templates.'),
			'method'        => 'textbox',
			'default'       => read_config_option('default_graph_height'),
			'size'          => '5',
			'value'         => $graph_height,
			'max_length'    => '5'
		),
		'graph_width' => array(
			'friendly_name' => __('Graph Template Width'),
			'description'   => __('The Width to be used when importing or updating Graph Templates.'),
			'method'        => 'textbox',
			'default'       => read_config_option('default_graph_width'),
			'size'          => '5',
			'value'         => $graph_width,
			'max_length'    => '5'
		)
	);

	if ($repo_id == 0) {
		return array_merge($form, $form2);
	} else {
		return $form2;
	}
}

function get_default_profile() {
	$default_profile = db_fetch_cell('SELECT id
		FROM data_source_profiles
		WHERE `default`="on"');

	if (empty($default_profile)) {
		$default_profile = db_fetch_cell('SELECT id
			FROM data_source_profiles
			ORDER BY id
			LIMIT 1');
	}

	return $default_profile;
}

function package_import() {
	global $actions, $hash_type_names, $device_classes;

	validate_request_vars();

	$display_hideme = false;

	$default = db_fetch_cell('SELECT id
		FROM package_repositories
		WHERE `default` = "on"');

	if (!isset_request_var('package_location')) {
		set_request_var('package_location', $default);
	}

	if (!isset_request_var('package_class')) {
		set_request_var('package_class', '0');
	}

	$device_classes = array_merge(array(0 => __('All')), $device_classes);

	if (get_request_var('package_location') == 0) {
		form_start('package_import.php', 'import', true);
	} else {
		form_start('package_import.php', 'import');
	}

	$repos = array_rekey(
		db_fetch_assoc('SELECT id, name
			FROM package_repositories
			WHERE enabled = "on"
			ORDER BY name'),
		'id', 'name'
	);

	$repos[0] = __('Local Package File');

	$pform = array(
		'package_location' => array(
			'friendly_name' => __('Package Location'),
			'description'   => __('Select the Location of the Packages that you wish to Import.'),
			'method'        => 'drop_array',
			'value'         => isset_request_var('package_location') ? get_nfilter_request_var('package_location') : $default,
			'array'         => $repos,
			'default'       => $default
		)
	);

	$default_profile = db_fetch_cell('SELECT id
		FROM data_source_profiles
		WHERE `default`="on"');

	if (empty($default_profile)) {
		$default_profile = db_fetch_cell('SELECT id
			FROM data_source_profiles
			ORDER BY id
			LIMIT 1');
	}

	$form = get_import_form(get_filter_request_var('package_location'), $default_profile);

	if (isset_request_var('package_location') && get_nfilter_request_var('package_location') == 0) {
		html_start_box(__('Package Import'), '100%', '', '3', 'center', '');

		draw_edit_form(
			array(
				'config' => array('no_form_tag' => true),
				'fields' => $pform
			)
		);

		html_end_box();

		html_start_box(__('Local Package Import'), '100%', true, '3', 'center', '');

		draw_edit_form(
			array(
				'config' => array('no_form_tag' => true),
				'fields' => $form
			)
		);

		html_end_box(true, true);

		form_hidden_box('save_component_import', '1', '');
		form_hidden_box('import_confirmed', '', '');

		form_save_button('', 'import', 'import', false);

		print "<div id='contents'></div>";

		form_dialog_box();
	} else {
		html_start_box( __('Package Import'), '100%', '', '3', 'center', '');

		draw_edit_form(
			array(
				'config' => array('no_form_tag' => true),
				'fields' => $pform
			)
		);

		html_end_box();

		html_start_box(__('Package Import Preferences'), '100%', true, '3', 'center', '');

		draw_edit_form(
			array(
				'config' => array('no_form_tag' => true),
				'fields' => $form
			)
		);

		html_end_box(true, true);

		html_start_box(__('Repository Based Package Import'), '100%', '', '3', 'center', '');

		$display_text = array(
			'name' => array(
				'display' => __('Name'),
			),
			'class' => array(
				'display' => __('Class'),
			),
			'version' => array(
				'display' => __('Version'),
			),
			'copyright' => array(
				'display' => __('Copyright'),
			),
			'author' => array(
				'display' => __('Author'),
			),
			'email' => array(
				'display' => __('Email'),
			),
		);

		unset($repos[0]);

		html_header_checkbox($display_text, false, '', true);

		$id = get_filter_request_var('package_location');

		$repo = json_decode(get_repo_manifest_file($id), true);

		$i = 0;

		if (cacti_sizeof($repo)) {
			$repos = $repo['manifest'];

			foreach($repos as $repo) {
				$subid = 'package_' . $i;

				form_alternate_row('line_' . $subid, true);

				if (!isset($repo['metadata']['copyright'])) {
					$copyright = __('Not Set');
				} else {
					$copyright = $repo['metadata']['copyright'];
				}

				if (isset($repo['metadata']['class']) && isset($device_classes[$repo['metadata']['class']])) {
					$class = $device_classes[$repo['metadata']['class']];
				} else {
					$class = __('Unknown');
				}

				form_selectable_ecell($repo['name'], $subid);
				form_selectable_ecell($class, $subid);
				form_selectable_ecell($repo['metadata']['version'], $subid);
				form_selectable_ecell($copyright, $subid);
				form_selectable_ecell($repo['metadata']['author'], $subid);
				form_selectable_ecell($repo['metadata']['email'], $subid);

				form_checkbox_cell($repo['name'], $subid);

				form_end_row();

				$i++;
			}
		} else {
			print "<tr class='tableRow odd'><td colspan='" . (cacti_sizeof($display_text)+1) . "'><em>" . __('No Packages Found') . '</em></td></tr>';
		}

		html_end_box(true);

		form_hidden_box('save_component_import', '1', '');
		form_hidden_box('import_confirmed', '', '');

		draw_actions_dropdown($actions);
	}

	?>
	<div id='contents'></div>
	<div id='dialog'></div>

	<script type='text/javascript'>
	function switchRepo() {
		var package_location    = $('#package_location').val();
		var remove_orphans      = $('#remove_orphans').is(':checked');
		var replace_svalues     = $('#replace_svalues').is(':checked');
		var data_source_profile = $('#data_source_profile').val();
		var package_class       = $('#package_class').val();
		var image_format        = $('#image_format').val();
		var graph_width         = $('#graph_width').val();
		var graph_height        = $('#graph_height').val();

		var strURL = urlPath + 'package_import.php' +
			'?package_location='  + package_location                +
			'&remove_orphans='    + remove_orphans                  +
			'&replace_svalues='   + replace_svalues                 +
			'&import_dsp='        + data_source_profile             +
			'&image_format='      + image_format                    +
			'&graph_height='      + graph_height                    +
			'&graph_width='       + graph_width;

		loadUrl({ url: strURL });
	}

	function packagesChanged() {
		var checks = ''

		$('input[id^="chk_package"]:checked').each(function() {
			checks += (checks != '' ? ',':'') + $(this).attr('id').replace('chk_package_', '');
		});

		if (checks != '') {
			$.get('package_import.php?action=details'                          +
				'&package_location='    + $('#package_location').val()         +
				'&data_source_profile=' + $('#data_source_profile').val()      +
				'&image_format='        + $('#image_format').val()             +
				'&graph_height='        + $('#graph_height').val()             +
				'&graph_width='         + $('#graph_width').val()              +
				'&replace_svalues='     + $('#replace_svalues').is(':checked') +
				'&remove_orphans='      + $('#remove_orphans').is(':checked')  +
				'&package_ids='         + checks, function(data) {

				$('#contents').html(data);
			});
		} else {
			$('#contents').empty();
		}
	}

	function checkSigner() {
		var checks = ''

		$('input[id^="chk_package"]:checked').each(function() {
			checks += (checks != '' ? ',':'') + $(this).attr('id').replace('chk_package_', '');
		});

		if (checks != '' || $('#package_location').val() == 0) {
			$.getJSON('package_import.php?action=verify'               +
				'&package_location=' + $('#package_location').val() +
				'&package_ids='      + checks, function(data) {

				if (data.status == 'error') {
					if ($('#import_dialog').length == 0) {
						$('body').append('<div id="import_dialog"><div id="import_message"></div></div>');
					}

					$('#import_message').html(data.message);
					$('#import_dialog').attr('title', data.title);

					$('#import_dialog').dialog({
						autoOpen: true,
						width: '400px',
						maxHeight: '400px',
						modal: true,
						buttons: {
							Cancel: function() {
								$(this).dialog('close');
							},
							Ok: function() {
								$('#import_dialog').dialog('close');
								trustSigner();

								if ($('#package_location').val() != '0') {
									packagesChanged();
								}
							}
						}
					});
				} else if (data.status == 'fileerror') {
					var mixedReasonTitle = data.title;
					var mixedOnPage      = data.header;
					sessionMessage   = {
						message: data.message,
						level: MESSAGE_LEVEL_MIXED
					};

					displayMessages();
				} else if ($('#package_location').val() != '0') {
					packagesChanged();
				}
			});
		} else if ($('#package_location').val() != '0') {
			$('#contents').empty();
		}
	}

	function trustSigner() {
		var checks = ''

		$('input[id^="chk_package"]:checked').each(function() {
			checks += (checks != '' ? ',':'') + $(this).attr('id').replace('chk_package_', '');
		});

		if (checks != '' || $('#package_location').val() == 0) {
			$.getJSON('package_import.php?action=accept'               +
				'&package_location='    + $('#package_location').val() +
				'&package_ids='         + checks, function(data) {
			});
		}
	}

	function makePackagesClickable() {
		$('#package_import3_child').find('tr[id^="line"]').on('click', function() {
			if (checkSigner()) {
				packagesChanged($(this));
			}
		});
	}

	$(function() {
		refreshMSeconds = 9999999;

		$('#package_location').off('change').on('change', function() {
			switchRepo();
		});

		$('#import_file').off('change').on('change', function() {
			var form = $('#import')[0];
			var data = new FormData(form);
			var formExtra = '?action=upload&package_location=0&preview_only=on';

			if ($('#remove_orphans').is(':checked')) {
				formExtra += '&remove_orphans=on';
			} else {
				formExtra += '&remove_orphans=';
			}

			if ($('#replace_svalues').is(':checked')) {
				formExtra += '&replace_svalues=on';
			} else {
				formExtra += '&replace_svalues=';
			}

			if ($('#trust_signer').is(':checked')) {
				formExtra += '&trust_signer=on';
			} else {
				formExtra += '&trust_signer=';
			}

			Pace.start();

			$.ajax({
				type: 'POST',
				enctype: 'multipart/form-data',
				url: urlPath + 'package_import.php' + formExtra,
				data: data,
				processData: false,
				contentType: false,
				cache: false,
				timeout: 10000,
				success: function (data) {
					if ($('#contents').length == 0) {
						$('#main').append('<div id="contents"></div>');
					} else {
						$('#contents').empty();
					}

					$('#contents').html(data);

					$('#import_confirmed').val('on');

					Pace.stop();

					checkSigner()
				},
				error: function (event) {
					if ($('#contents').length == 0) {
						$('#main').append('<div id="contents"></div>');
					} else {
						$('#contents').empty();
					}

					$('#contents').html(data);

					Pace.stop();
				}
			});
		});

		makePackagesClickable();

		$('#selectall').change(function() {
			if (checkSigner()) {
				packagesChanged();
			}
		});

		$('.import_label').button();
		$('.import_button').change(function() {
			text=this.value;
			setImportFile(text);
		});

		setImportFile(noFileSelected);

		function setImportFile(fileText) {
			$('.import_text').text(fileText);
		}
	});
	</script>
	<?php

	form_end();
}

function form_dialog_box() {
	print '<div style="display:none">
		<div id="import_dialog" title="">
			<div id="import_message"></div>
		</div>
	</div>';
}

function get_repo_file($repo_id, $filename = 'package.manifest', $javascript = false) {
	$repo = db_fetch_row_prepared('SELECT *
		FROM package_repositories
		WHERE id = ?',
		array($repo_id));

	if (cacti_sizeof($repo)) {
		if ($repo['repo_type'] == 0) { // GitHub
			$repoloc = str_replace('github.com', 'raw.githubusercontent.com', $repo['repo_location']);
			$file = $repoloc . '/' . $repo['repo_branch'] . '/' . $filename;

			$data = file_get_contents($file);

			if ($data != '') {
				return $data;
			} elseif (!$javascript) {
				raise_message('repo_missing', __('The Repo \'%s\' is NOT Reachable on GitHub or the \'%s\' file is missing or it could be an invalid branch.  Valid Package Locations are normally: https://github.com/Author/RepoName/.', $repo['name'], $filename), MESSAGE_LEVEL_ERROR);
			}
		} elseif ($repo['repo_type'] == 2) { // Direct URL
			$file = $repo['repo_location'] . '/' . $filename;

			$context = array(
				'ssl' =>array(
					'verify_peer'      => false,
					'verify_peer_name' => false,
				),
			);

			$data = file_get_contents($file, false, stream_context_create($context));

			if ($data != '') {
				return $data;
			} elseif (!$javascript) {
				raise_message('repo_missing', __('The Repo \'%s\' is NOT Reachable at the URL Location or the package.manifest file is missing.', $repo['name']), MESSAGE_LEVEL_ERROR);
			}
		} else { // Server Directory
			$file = $repo['repo_location'] . '/' . $filename;

			if (file_exists($file)) {
				$data = file_get_contents($file);

				if ($data != '') {
					return $data;
				} elseif (!$javascript) {
					raise_message('repo_exists', __('The Repo \'%s\' is Reachable on the Local Cacti Server.  But not data returned from the manifest file.', $repo['name']), MESSAGE_LEVEL_ERROR);
				}
			} elseif (!$javascript) {
				raise_message('repo_missing', __('The Repo \'%s\' is NOT Reachable on the Local Cacti Server or the package.manifest file is missing.', $repo['name']), MESSAGE_LEVEL_ERROR);
			}
		}
	}

	return false;
}

function get_repo_manifest_file($repo_id) {
	return get_repo_file($repo_id, 'package.manifest');
}

function is_tmp_writable() {
	$tmp_dir  = sys_get_temp_dir();
	$tmp_len  = strlen($tmp_dir);
	$tmp_dir .= ($tmp_len !== 0 && substr($tmp_dir, -$tmp_len) === '/') ? '': '/';
	$is_tmp   = is_resource_writable($tmp_dir);

	return $is_tmp;
}

function package_prepare_import_array(&$templates, &$files, $package_name, $package_filename, $import_info) {
	global $hash_type_names;

	/**
	 * This function will create an array of item types and their status
	 * the user will have an option to import select items based upon
	 * these values.
	 *
	 * $templates['template_hash'] = array(
	 *    'package'      => 'some_package_name',
	 *    'package_file' => 'some_package_filename',
	 *    'type'         => 'some_type',
	 *    'type_name'    => 'some_type_name',
	 *    'name'         => 'some_name',
	 *    'status'       => 'some_status'
	 * );
	 *
	 * $files[$package_filename|$package_name] = array(
	 *    'filename' => 'somefilename'
	 * );
	 */

	if (cacti_sizeof($import_info)) {
		if (cacti_sizeof($import_info[1])) {
			foreach($import_info[1] as $filename => $status) {
				$files["$package_filename|$package_name"][$filename] = $status;
			}
		}

		foreach ($import_info[0] as $type => $type_array) {
			if ($type == 'files') {
				continue;
			}

			foreach ($type_array as $index => $vals) {
				$hash = $vals['hash'];

				if (!isset($templates[$hash])) {
					$templates[$hash]['package']      = $package_name;
					$templates[$hash]['package_file'] = $package_filename;
					$templates[$hash]['status']       = $vals['type'];;
				} else {
					$templates[$hash]['package']      .= '<br>' . $package_name;
					$templates[$hash]['package_file'] .= '<br>' . $package_filename;
					$templates[$hash]['status']       .= '<br>' . $vals['type'];;
				}

				$templates[$hash]['type']      = $type;
				$templates[$hash]['type_name'] = $hash_type_names[$type];
				$templates[$hash]['name']      = $vals['title'];

				unset($vals['title']);
				unset($vals['result']);
				unset($vals['hash']);
				unset($vals['type']);

				if (isset($vals['dep'])) {
					unset($vals['dep']);
				}

				if (cacti_sizeof($vals)) {
					$templates[$hash]['vals'][$package_name] = $vals;
				}
			}
		}
	}
}

