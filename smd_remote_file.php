<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_remote_file';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.6.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Integrate remote URL and non web-accessible files with the Files panel';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '1';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '3';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@owner smd_remote_file
#@language en, en-gb, en-us
#@file
smd_remote_secure => Secure
smd_remote_secure_opts => Security options
smd_remote_secure_uploaded => Secure file uploaded
smd_remote_upload => Upload
smd_remote_url => or URL
smd_remote_urls => Remote URLs
smd_remote_url_tooltip => Enter a URL to upload to Textpattern
#@prefs
smd_remote_all => All users
smd_remote_download_status => Permitted download statuses
smd_remote_file => Remote files
smd_remote_internet => The Internet
smd_remote_limit_privs => Limit downloads to this priv level
smd_remote_mechanism => Allow downloads from
smd_remote_secure_loc => Secure location
smd_remote_secure_path => Secure file path (not web-accessible)
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_remote_file
 *
 * A Textpattern CMS plugin for storing and serving files from off-site and secure (non-docroot) locations
 *  -> Manage links to cloud-based files directly from the Txp Files panel, as if they were native
 *  -> Multiple sources for the same remote file supported, for load balancing / bandwidth saving
 *  -> Manage files in non-web-accessible (i.e. secure) locations on your host server
 *  -> Secure files can optionally be served to logged-in users using Hidden / Pending status
 *  -> No modifications to Txp required

 * @author Stef Dawson
 * @link   http://stefdawson.com/
 */

// TODO:
//  * Set URL / secure for missing files (remote_update / file_save step needs to move file if file names different and update DB)
//  * Safe parameters inside the .safe file to govern access restriction, expiry, download count, etc
//  * is_writable() folder checks, and other error traps.
$smd_remote_prefs = smd_remote_get_prefs();

if (txpinterface === 'admin') {
    global $event;

    add_privs('prefs.smd_remote_file', '1');
    add_privs('plugin_prefs.smd_remote_file', '1');
    register_callback('smd_remote_dispatcher', 'plugin_prefs.smd_remote_file');
    register_callback('smd_remote_welcome', 'plugin_lifecycle.smd_remote_file');

    if ($event === 'file') {
        register_callback('smd_remote_file', 'file', 'file_replace', 1);
        register_callback('smd_remote_file', 'file', 'file_create', 1);
        register_callback('smd_remote_file', 'file', 'file_insert', 1);
        register_callback('smd_remote_file_update', 'file', 'file_save');
        register_callback('smd_remote_pre_multi_edit', 'file', 'file_multi_edit', 1);
        register_callback('smd_remote_multi_edit', 'file', 'file_multi_edit');
        register_callback('smd_remote_file_upload', 'file_ui', 'upload_form');
        register_callback('smd_remote_file_edit', 'file');
    }
}

if (txpinterface === 'public') {
    register_callback('smd_remote_download', 'file_download');
}

// This privilege can be overridden with smd_user_manager
// Caveat: Privs panel list needs to be saved from smd_um first
add_privs('file.download', get_pref('smd_remote_limit_privs', $smd_remote_prefs['smd_remote_limit_privs']['default']));

/**
 * Plugin jump off point.
 *
 * @param  string $evt Textpattern event (panel)
 * @param  string $stp Textpattern step (action)
 */
function smd_remote_dispatcher($evt, $stp)
{
    global $event;

    $available_steps = array(
        'smd_secure_uploaded' => false,
        'smd_remote_prefs'    => false,
    );

    if (!$stp or !bouncer($stp, $available_steps)) {
        $stp = 'smd_remote_prefs';
    }

    $stp();
}

/**
 * Initialization after install.
 *
 * @param  string $evt Textpattern event (panel)
 * @param  string $stp Textpattern step (action)
 * @return string      Greeting text
 */
function smd_remote_welcome($evt, $stp)
{
    $msg = '';

    switch ($stp) {
        case 'installed':
            smd_remote_prefs_install();
            $msg = 'Externalise your files.';
            break;
        case 'deleted':
            smd_remote_prefs_remove();
            break;
    }

    return $msg;
}

/**
 * Install the prefs if necessary.
 *
 * When operating under a plugin cache environment, the install lifecycle
 * event is never fired, so this is a fallback.
 *
 * The lifecycle callback remains for deletion purposes under a regular installation,
 * since the plugin cannot detect this in a cache environment.
 *
 * @see smd_remote_welcome()
 */
function smd_remote_prefs_install()
{
    $smd_remote_prefs = smd_remote_get_prefs();

    foreach ($smd_remote_prefs as $key => $prefobj) {
        if (get_pref($key, null) === null) {
            $viz = isset($prefobj['visibility']) ? $prefobj['visibility'] : PREF_GLOBAL;
            set_pref($key, doSlash($prefobj['default']), 'smd_remote_file', $prefobj['type'], $prefobj['html'], $prefobj['position'], $viz);
        } elseif (fetch('html', 'txp_prefs', 'name', 'smd_remote_mechanism')) {

        }
    }
}

/**
 * Delete plugin prefs.
 *
 * @param  boolean $showpane Whether to display a success message or not
 */
function smd_remote_prefs_remove()
{
    safe_delete('txp_prefs', "name='smd_remote_%'");
}

/**
 * Handle the prefs panel.
 */
function smd_remote_prefs()
{
    smd_remote_prefs_install();
    header('Location: ?event=prefs#prefs_group_smd_remote_file');
}

/**
 * Add markup around the upload forms.
 *
 * @param  string $evt Textpattern event (panel)
 * @param  string $stp Textpattern step (action)
 * @param  string $def Default content
 * @return string      HTML
 */
function smd_remote_file_upload($evt, $stp, $def)
{
    global $step;

    $smd_remote_prefs = smd_remote_get_prefs();

    $fid = gps('id');

    $rfhelpLink = '<a target="_blank"'.
            ' href="https://stefdawson.com/support/smd_remote_file_url_popup.html"'.
            ' onclick="popWin(this.href); return false;" class="pophelp">?</a>';
/*  $sfhelpLink = '<a target="_blank"'.
            ' href="http://stefdawson.com/support/smd_remote_file_secure_popup.html"'.
            ' onclick="popWin(this.href); return false;" class="pophelp">?</a>';
*/
    $mech = get_pref('smd_remote_mechanism', $smd_remote_prefs['smd_remote_mechanism']['default']);
    $dosec = in_list('secure', $mech);
    $dorem = in_list('remote', $mech);
    $rs = array('filename' => '');

    if ($fid) {
        $rs = safe_row('*', 'txp_file', "id='".doSlash($fid)."'");
    }

    $show_rem = ((strpos($rs['filename'], '.link') === false) && $step == 'file_edit');
    $is_safe = ((strpos($rs['filename'], '.safe') !== false) && (($step == 'file_edit') || ($step == 'file_replace')));

    $def = preg_replace('/<input type="reset" value="Reset" \/>\s*<input type="submit" value="Upload" \/>/',
        (($dosec)
            ? sp.checkbox('smd_secure', 1, ($is_safe ? 1 : 0))
                . ' <label for="smd_secure">'.gTxt('smd_remote_secure').'</label>'
            : ''
        )
        . ' </span>' .
        (($dorem && !$show_rem && (!$is_safe))
            ? trim(form(graf(gTxt('smd_remote_url').sp.$rfhelpLink.sp.fInput('text', 'smd_remote_url', '', 'edit', gTxt('smd_remote_url_tooltip'), '', '32').sp.
                    fInput('submit', '', gTxt('smd_remote_upload')))
            ))
            : ''
        ),
        $def);

    return $def;
}

/**
 * Generic callback, fired before the Files page has loaded.
 *
 * Intercepts any events/steps that deal with secure/remote files.
 *
 * @param  string $evt Textpattern event (panel)
 * @param  string $stp Textpattern step (action)
 */
function smd_remote_file($evt, $stp)
{
    global $file_base_path, $txp_user, $step, $theme;

    smd_remote_prefs_install();
    extract(doSlash(gpsa(array('smd_remote_url', 'smd_secure', 'category', 'permissions', 'description', 'title'))));

    $finfo = array(
        'title'       => $title,
        'category'    => $category,
        'permissions' => $permissions,
        'description' => $description,
        'status'      => '4',
        'created'     => 'now()',
        'modified'    => 'now()',
        'author'      => doSlash($txp_user),
    );

    if ($smd_remote_url) {
        $url = trim($smd_remote_url);

        // Only intercept remote files; leave everything else for Txp to manage.
        if (strpos($url, 'http') === 0) {
            $hdrs = smd_get_headers($url, 1);
            $finfo['size'] = ($hdrs === false || !isset($hdrs['content-length'])) ? 1 : $hdrs['content-length'];

            // Make a filename and full path: unencoded.
            $dest_filename = basename(urldecode($url)).'.link';
            $dest_filepath = build_file_path($file_base_path, $dest_filename);
            $finfo['filename'] = doSlash($dest_filename);

            if (file_exists($dest_filepath)) {
                smd_remote_file_write($dest_filepath, $dest_filename, $url);
            } else {
                // File doesn't exist so create it and put the URL inside.
                $tmp = tempnam(get_pref('tempdir'), 'smd_');
                $handle = fopen($tmp, 'w');
                fwrite($handle, $url.n);
                fclose($handle);
                rename($tmp, $dest_filepath);

                // Add the file to Textpattern.
                // Can't use file_db_add() because this step is pre txp_file.php being loaded :-(
//              $ret = file_db_add(doSlash($dest_filename), $category, $permissions, $description, $size, $title);
                $ret = smd_remote_file_insert($finfo);

                // Fake the step so Txp's internal file upload step is not called
                // TODO: success message
                $step = 'smd_secure_uploaded';
            }
        } else {
            // TODO: Upload failed message.
            // gTxt('file_upload_failed') . ((empty($smd_remote_url)) ? ' - '.gTxt('upload_err_no_file') : '');
        }
    } elseif ($smd_secure) {
        if ($stp == 'file_create') {
            $orig_filename = ps('filename');
            $dest_filename = sanitizeForFile($orig_filename);
            $orig_filepath = build_file_path($file_base_path, $orig_filename);
            $sz = filesize($orig_filepath);
        } else {
            // Get filenames and create full paths: unencoded.
            $fn = $_FILES['thefile']['name'];
            $tn = $_FILES['thefile']['tmp_name'];
            $sz = $_FILES['thefile']['size'];

            if ($stp == 'file_replace') {
                // Replaced files keep the same file name as before (shrug, Txp).
                $id = ps('id');
                $dest_filename = basename(safe_field('filename', 'txp_file', 'id='.doSlash($id)), '.safe');
            } else {
                $dest_filename = sanitizeForFile($fn);
            }
        }

        $dest_safename = $dest_filename.'.safe';
        $dest_realpath = build_file_path(get_pref('smd_remote_secure_path'), $dest_filename);
        $dest_origpath = build_file_path($file_base_path, $dest_filename);
        $dest_filepath = build_file_path($file_base_path, $dest_safename);
        $finfo['filename'] = doSlash($dest_safename);
        $finfo['size'] = $sz;

        if (file_exists($dest_filepath)) {
            // Update secure info for existing placeholder.
            smd_secure_file_write($dest_filepath, $dest_filename);
        } elseif ($stp == 'file_replace') {
            // File was insecure, now secure.
            safe_update('txp_file', "filename='" . doSlash($dest_safename) . "'", "id='" . doSlash($id) . "'");
            smd_secure_file_create($dest_filepath, $dest_filename);

            if (file_exists($dest_origpath)) {
                unlink($dest_origpath);
            }
        } else {
            // File doesn't exist so create it.
            // Reserve file contents (3rd param) for future meta info use
            // (e.g. start/expiry date, max download count, etc).
            smd_secure_file_create($dest_filepath, $dest_filename);
            $id = smd_remote_file_insert($finfo);
        }

        // Move the uploaded file to the secure location.
        if (isset($tn)) {
            move_uploaded_file($tn, $dest_realpath);
        } else {
            rename($orig_filepath, $dest_realpath);
        }

        if (isset($id)) {
            smd_remote_set_size($id);
        }

        // Fake the step so Txp's internal file upload step is not called.
        // TODO: success message.
        $step = 'smd_secure_uploaded';
    } elseif (($stp == 'file_replace') && !$smd_secure) {
        $id = ps('id');
        $filename = safe_field('filename', 'txp_file', 'id='.doSlash($id));

        if (strpos($filename, '.safe') !== false) {
            // File used to be secure, now isn't so:
            //  a) delete secure file
            //  b) delete .safe file
            //  c) rename DB entry to remove .safe
            //  d) leave Txp to save the file as normal
            $real_filename = basename($filename, '.safe');
            $dest_file = build_file_path($file_base_path, $filename);
            $safe_file = build_file_path(get_pref('smd_remote_secure_path'), $real_filename);
            safe_update('txp_file', "filename='" . doSlash($real_filename) . "'", "filename='" . doSlash($filename) . "'");
            unlink($dest_file);
            unlink($safe_file);
        }
    }
}

/**
 * Insert the file metadata to the database.
 *
 * @param  array $finfo File details to store
 * @return boolean      Success status
 */
function smd_remote_file_insert($finfo)
{

    $ret = safe_insert('txp_file',
        "filename    = '{$finfo['filename']}',
         title       = '{$finfo['title']}',
         category    = '{$finfo['category']}',
         permissions = '{$finfo['permissions']}',
         description = '{$finfo['description']}',
         status      = '{$finfo['status']}',
         size        = '{$finfo['size']}',
         created     = '{$finfo['created']}',
         modified    = '{$finfo['modified']}',
         author      = '{$finfo['author']}'
    ");

    return $ret;
}

/**
 * Fired after Files panel has loaded to insert extra fields into UI.
 *
 * @param  string $evt Textpattern event (panel)
 * @param  string $stp Textpattern step (action)
 * @return string      Javascript
 */
function smd_remote_file_edit($evt, $stp)
{
    global $file_base_path;

    $smd_remote_prefs = smd_remote_get_prefs();

    $jsadd = array();

    if ($stp == 'file_edit' || $stp == 'file_replace') {
        $id = assert_int(gps('id'));
        // TODO: is there a global page var containing filename instead of re-requesting it from DB?
        $rs = safe_row('filename', 'txp_file', 'id = '. $id);

        if (strpos($rs['filename'], '.link') !== false) {
            $filepath = build_file_path($file_base_path, $rs['filename']);
            $contents = smd_remote_file_list($filepath, 0, 1);

            $ul_form = preg_replace('/\s+/', ' ', trim(inputLabel('smd_remote_urls', text_area('smd_remote_url', '100', '400', implode("\u000D", $contents)))));
            $jsadd[] = 'jQuery(".replace-file").remove();';
            $jsadd[] = 'jQuery(".edit-file-status").before(\''.$ul_form.'\');';
        } elseif (strpos($rs['filename'], '.safe') !== false) {
            $filepath = build_file_path($file_base_path, $rs['filename']);
            $contents = smd_remote_file_list($filepath, 0, 1);

            $ul_form = preg_replace('/\s+/', ' ', inputLabel('smd_remote_secure_opts', text_area('smd_secure_opts', '100', '400', implode("\u000D", $contents))));
            $jsadd[] = 'jQuery(".edit-file-status").before(\''.$ul_form.'\');';
        }
    } else {
        // Files panel main list page.
        $mech = get_pref('smd_remote_mechanism', $smd_remote_prefs['smd_remote_mechanism']['default']);
        $dosec = in_list('secure', $mech);

        if ($dosec) {
            $markup = sp.checkbox('smd_secure',1,0)
                    . ' <label for="smd_secure">'.gTxt('smd_remote_secure').'</label>';
            $jsadd[] = 'jQuery("#assign_file p").append(\''.$markup.'\');';
        }
    }

    // Inject any javascript onto the page.
    if ($jsadd) {
        echo smd_remote_js(implode(n, $jsadd));
    }
}

/**
 * Rewrite metadata after file has changed.
 */
function smd_remote_file_resync()
{
    global $file_base_path;

    extract(gpsa(array('smd_remote_url', 'smd_secure_opts', 'id')));
    $id = assert_int($id);
    $rs = safe_row('filename','txp_file','id = '.$id);
    $dest_filepath = build_file_path($file_base_path, $rs['filename']);

    if (strpos($rs['filename'], '.link') !== false) {
        // When a .link file is updated with a new set of URLs, the existing file name
        // must remain unchanged.
        $urls = explode(n, trim($smd_remote_url));
        $real_filename = basename($rs['filename'], '.link');
        $valid_urls = array();

        foreach ($urls as $url) {
            $url = trim($url);

            // Does the file start with http and end with the filename?
            if ((strpos($url, 'http') === 0) && (preg_match("/".$real_filename."$/", $url) === 1)) {
                $valid_urls[] = $url;
          }
        }

        $valid_urls = array_unique($valid_urls);

        smd_remote_file_write($dest_filepath, $rs['filename'], implode(n, $valid_urls), 'w');
    } elseif (strpos($rs['filename'], '.safe') !== false) {
        $content = explode(n, trim($smd_secure_opts));
        smd_secure_file_write($dest_filepath, $rs['filename'], implode(n, $content), 'w');
    }
}

/**
 * Fix file size after save.
 *
 * Every time a file is saved/edited, Txp recalculates its size from
 * the file in the /files dir (grrr). This is undesirable so it is
 * replaced with the size of the remote/secure file instead.
 */
function smd_remote_file_update()
{
    extract(gpsa(array('id')));

    smd_remote_file_resync();
    smd_remote_set_size($id);
}

/**
 * Fetch the remote file size and sync it with Txp.
 *
 * Set the size of the given Txp database file to that of its
 * corresponding "real" remote/secure file size.
 *
 * @param  int|string $id_or_file Reference to the file (numeric ID or filename)
 */
function smd_remote_set_size($id_or_file)
{
    global $file_base_path;

    if (is_numeric($id_or_file)) {
        $filename = trim(safe_field('filename', 'txp_file', 'id='.intval($id_or_file)));
    } else {
        $filename = trim($id_or_file);
    }

    if (strpos($filename, '.link') !== false) {
        $filepath = build_file_path($file_base_path, $filename);
        $url = smd_remote_file_list($filepath, 1, 1);

        if (count($url) > 0) {
            $hdrs = smd_get_headers($url[0], 1);
            $size = ($hdrs === false || !isset($hdrs['content-length'])) ? 1 : $hdrs['content-length'];
            safe_update('txp_file', 'size='.$size, "filename='".$filename."'");
        }
    } elseif (strpos($filename, '.safe') !== false) {
        $filepath = build_file_path(get_pref('smd_remote_secure_path'), basename($filename, '.safe'));
        $size = filesize($filepath);
        safe_update('txp_file', 'size='.$size, "filename='".$filename."'");
    }
}

/**
 * Multi-edit delete: part 1.
 *
 * Multi-delete is done in two passes.
 * Step 1: Grab a list of IDs that are about to be removed.
 *
 * @param  string $evt Textpattern event (panel)
 * @param  string $stp Textpattern step (action)
 * @see    smd_remote_multi_edit for part 2
 */
function smd_remote_pre_multi_edit($evt, $stp)
{
    global $smd_remote_selected;

    $selected = ps('selected');

    if ($selected && is_array($selected)) {
        $selected = array_map('assert_int', $selected);
        $smd_remote_selected = safe_column('filename', 'txp_file', 'id in (' . implode(',', $selected) . ')');
    }
}

/**
 * Multi-edit delete: part 2.
 *
 * Multi-delete is done in two passes.
 * Step 2: Effect changes to safe files if their counterpart .safe
 * index file no longer exists.
 *
 * @param  string $evt Textpattern event (panel)
 * @param  string $stp Textpattern step (action)
 * @see    smd_remote_pre_multi_edit for part 1
 */
function smd_remote_multi_edit($evt, $stp)
{
    global $smd_remote_selected, $file_base_path;

    $method   = ps('edit_method');

    switch ($method) {
        case 'delete':
            $safe_path = get_pref('smd_remote_secure_path');

            foreach ($smd_remote_selected as $file) {
                $dest_filepath = build_file_path($file_base_path, $file);

                if (!file_exists($dest_filepath)) {
                    $safe_file = build_file_path($safe_path, basename($file, '.safe'));

                    if (file_exists($safe_file)) {
                        unlink($safe_file);
                    }
                }
            }
            break;
    }
}

/**
 * Callback for uploading a URL from the Files tab.
 */
function smd_remote_file_create()
{
    global $file_base_path;
}

/**
 * Placeholder stub called after a file is uploaded.
 *
 * Cheap redirect step to prevent Txp's internal file processing from trying to
 * move files that have already been relocated.
 */
function smd_secure_uploaded()
{
}

/**
 * Store a secure file in the chosen destination.
 *
 * @param  string $filepath  Full path to the file for writing
 * @param  string $filename  Filename of the destination
 * @param  string $content   Content to write (unused at present)
 * @param  string $writeMode Whether to overwrite ('w') or append ('a')
 */
function smd_secure_file_write($filepath, $filename, $content = '', $writeMode = 'a')
{
    // No need to actually write anything to the file right now.
    // TODO: use the file contents as a way to store meta info like
    // max download count, start/expiry dates, etc.

    // Set the size, just in case.
    smd_remote_set_size($filename);
}

/**
 * Create a new secure file at the chosen destination.
 *
 * @param  string $filepath  Full path to the file for writing
 * @param  string $filename  Filename of the destination
 * @param  string $content   Content to write
 */
function smd_secure_file_create($filepath, $filename, $content = '')
{
    $content = ($content) ? $content : 'smd_remote_file placeholder'.n;
    $tmp = tempnam(get_pref('tempdir'), 'smd_');
    $handle = fopen($tmp, 'w');
    fwrite($handle, $content);
    fclose($handle);
    rename($tmp, $filepath);

    // Set the size, just in case.
    smd_remote_set_size($filename);
}

/**
 * Append a URL to the given file.
 *
 * If writeMode="w" the whole file is replaced.
 *
 * @param  string $filepath  Full path to the file for writing
 * @param  string $filename  Filename of the destination
 * @param  string $url       The URL to inject into the file contents
 * @param  string $writeMode Whether to overwrite ('w') or append ('a')
 * @return [type]            [description]
 */
function smd_remote_file_write($filepath, $filename, $url, $writeMode = 'a')
{
    // Read the whole file because we only want to add the URL if it's not there already.
    $lines = smd_remote_file_list($filepath, 0, 1);

    if (!in_array($url, $lines) || $writeMode == 'w') {
        $handle = fopen($filepath, $writeMode);
        fwrite($handle, $url.n);
        fclose($handle);
    }

    // Set the size, just in case.
    smd_remote_set_size($filename);
}


/**
 * Fetch a list of remote files from the placeholder file.
 *
 * Read the contents of the chosen file (full path required) and get
 * lines from within, adding them to an array.
 *
 * @param  stirng  $fname  Full path to the file to read
 * @param  integer $qty    How many rows to extract. 0 = all.
 * @param  integer $offset Which line to start from. 1 = 1st row, 2 = 2nd row and so on. 0 = a random row
 * @return array           List of files
 */
function smd_remote_file_list($fname, $qty = 1, $offset = 0)
{
    $out = array();

    if (file_exists($fname)) {
        $fd = fopen($fname, 'r');

        // Read the whole file in (yes there's the file() call, but fgets() is
        // supposedly quicker on txt files).
        $lines = array();

        while (!feof($fd)) {
            $line = rtrim(fgets($fd));
            if ($line != '') {
               $lines[] = $line;
            }
        }

        fclose ($fd);

        if ($offset == 0) {
            shuffle($lines);
            $offset = 1;
        }

        $offset = ($offset > count($lines)) ? 1 : $offset;
        $out = ($qty == 0) ? $lines : array_slice($lines, $offset-1, $qty);
    }

    return $out;
}

/**
 * Permit secure files to be downloaded in a similar manner to native Txp files.
 *
 * @param  array   $finfo File info block
 * @param  integer $id    File id to serve
 * @return mixed          File, or 404 if not found
 *
 * @todo Align this with improved file download hooks in 4.7.
 */
function smd_serve_secure_file($finfo, $id)
{
    global $pretext, $file_base_path;

    $finfo['path'] = (isset($finfo['path'])) ? $finfo['path'] : $file_base_path;
    $fullpath = build_file_path($finfo['path'], $finfo['filename']);

    if (is_file($fullpath)) {
        $sent = 0;
        header('Content-Description: File Download');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($finfo['filename']) . '"; size = "'.$finfo['size'].'"');
        @ini_set('zlib.output_compression', 'Off');
        @set_time_limit(0);
        @ignore_user_abort(true);

        if ($file = fopen($fullpath, 'rb')) {
            while(!feof($file) && (connection_status()==0)) {
                echo fread($file, 1024*64);
                $sent += (1024*64);
                ob_flush();
                flush();
            }

            fclose($file);

            // Record download.
            if ((connection_status()==0) and !connection_aborted() ) {
                safe_update('txp_file', 'downloads=downloads+1', 'id='.intval($id));
                log_hit('200');
            } else {
                $pretext['request_uri'] .= ($sent >= $finfo['size'])
                    ? '#aborted'
                    : '#aborted-at-'.floor($sent*100 / $finfo['size']).'%';
                log_hit('200');
            }

            // Secure download down: game over.
            exit(0);
        }
    }

    return 404;
}

/**
 * Permit remote files to be downloaded in a similar manner to native Txp files.
 *
 * Called just before a download is initiated.
 *
 * @param  string $evt Textpattern event (panel)
 * @param  string $stp Textpattern step (action)
 * @return mixed       File, or 404 if not found
 *
 * @todo Align this with improved file download hooks in 4.7.
 * @todo Check the kludge reflects the current practice in 4.7.
 */
function smd_remote_download($evt, $stp)
{
    global $pretext, $id, $file_base_path, $file_error;

    if ($evt == 'file_download') {
        if (isset($file_error)) {
            // Kludge. Since Txp forbids any downloads of non-live statuses by effectively removing any trace
            // of the reference to the file from $pretext, we need to re-discover the file's ID from the URL.
            // Most of this is taken from the beginning of pretext() in publish.php. By the time pretext ends
            // the data has been scrubbed so a callback on pretext_end is too late
            $request_uri = preg_replace("|^https?://[^/]+|i","",serverSet('REQUEST_URI'));

            // IIS fix
            if (!$request_uri and serverSet('SCRIPT_NAME'))
                $request_uri = serverSet('SCRIPT_NAME').((serverSet('QUERY_STRING')) ? '?'.serverSet('QUERY_STRING') : '');
                // another IIS fix
            if (!$request_uri and serverSet('argv')) {
                $argv = serverSet('argv');
                $request_uri = @substr($argv[0], strpos($argv[0], ';') + 1);
            }

            $subpath = preg_quote(preg_replace("/https?:\/\/.*(\/.*)/Ui","$1",hu),"/");
            $req = preg_replace("/^$subpath/i","/",$request_uri);

            extract(chopUrl($req));

            switch ($u1) {
                case urldecode(strtolower(urlencode(gTxt('file_download')))):
                $id = (!empty($u2)) ? $u2 : '';
            }
        }

        // Get the "true" filename info and its status.
        $real_file = safe_row('filename, size, status, author', 'txp_file', 'id='.intval($id));
        $statuses = do_list(get_pref('smd_remote_download_status'));

        // Handle secure downloads via non-docroot location or hidden/pending status.
        if ((in_array($real_file['status'], $statuses)) || (strpos($real_file['filename'], '.safe') > 0)) {
            if (strpos($real_file['filename'], '.safe') > 0) {
                $real_file['path'] = get_pref('smd_remote_secure_path');
                $real_file['filename'] = basename($real_file['filename'], '.safe');
            }

            if (function_exists('smd_um_has_privs')) {
                // Serve the file if the current logged in user is a member of the
                // file.download or file.download.status_num areas.
                if (smd_um_has_privs(array('area' => 'file.download, file.download.'.$real_file['status']), 'OK')) {
                    $file_error = smd_serve_secure_file($real_file, $id);
                }
            }

            // Serve the file if:
            // a) the current logged in user is the one who uploaded the file, or
            // b) the privs of the logged in user match the one(s) in the pref
            $smd_rem_ili = is_logged_in();

            if (($real_file['author'] == $smd_rem_ili['name']) || (in_list($smd_rem_ili['privs'], get_pref('smd_remote_limit_privs')))) {
                $file_error = smd_serve_secure_file($real_file, $id);
            }
        }

        // Serve the file if it's a remote download.
        if ((!isset($file_error)) && (strpos($real_file['filename'], '.link') > 0)) {
            $choose = 0;

            // Get any overriding value of smd_choose from the query string
            if ($pretext['qs']) {
                list($qkey, $qval) = explode('=', $pretext['qs']);
                if ($qkey == 'smd_choose') {
                    if ($qval > 0) {
                        $choose = intval($qval);
                    }
                }
            }

            // The file size, however, is that of the remote file.
            $remoteURL = smd_remote_file_list(build_file_path($file_base_path, $real_file['filename']), 1, $choose);

            if (count($remoteURL) > 0) {
                $url = $remoteURL[0];
                // Test the file exists: slow, but reduces false download count increments.
                $hdrs = smd_get_headers($url, 1);
                $allkey = strtolower(implode(' ', array_keys($hdrs)));

                if (strpos($allkey, '200') > 0 && strpos($allkey, 'ok') > 0) {
                    header('Content-Description: File Download');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . basename($real_file['filename']) . '"; size = "'.$real_file['size'].'"');
                    // Fix for lame IE 6 pdf bug on servers configured to send cache headers
                    header('Cache-Control: private');
                    @ini_set('zlib.output_compression', 'Off');
                    @set_time_limit(0);
                    @ignore_user_abort(true);

                    // Hand-off to the remote file
                    header('Location: ' . $url);

                    // record download if the file sizes match
                    if (intval($hdrs['content-length']) == intval($real_file['size'])) {
                        safe_update('txp_file', 'downloads=downloads+1', 'id='.intval($id));
                        log_hit('200');
                    }

                    // Remote download done: game over.
                    exit(0);

                } else {
                    $file_error = 404;
                }
            } else {
                $file_error = 404;
            }
        }
    }

    // Remote/secure download not done - leave to Txp to handle error
    // or provide "local" file download.
    return;
}

/**
 * Inject jQuery DOMReady content into the admin-side pages.
 *
 * @param  string $content Javascript content block
 * @return string          Wrapped content
 */
function smd_remote_js($content)
{
    return script_js('jQuery(function() {' .n. $content .n. '});');
}

/**
 * Fetch header information from a remote source.
 *
 * Like PHP's get_headers() but using curl to bypass servers that
 * disable allow_url_fopen.
 *
 * @param  string  $url    The destination to fetch
 * @param  integer $format The format to return the information. 0=everything, 1=delimited
 */
function smd_get_headers($url, $format = 0)
{
    if (!$url) {
        return false;
    }

    $uinfo=parse_url($url);

    if (is_callable('checkdnsrr') && !checkdnsrr($uinfo['host'].'.','MX') && !checkdnsrr($uinfo['host'].'.','A')) {
        return false;
    }

    $headers = array();
    $url = str_replace(' ', '%20', trim($url));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $data = curl_exec($ch);
    curl_close($ch);

    if ($data) {
        if($format == 1) {
            foreach(preg_split("/((\r?\n)|(\n?\r))/", $data) as $line) {
                $line = trim($line);

                if ($line == '') continue;

                $exploded = explode(': ', $line);
                $key = strtolower(array_shift($exploded));

                if ($key == $line) {
                    // No delimiter: take the whole line.
                    $headers[] = $line;
                } else {
                    $headers[$key] = substr($line, strlen($key) + 2);
                }

                unset($key);
            }
        } else {
            $headers[] = $data;
        }

        return $headers;
    } else {
        return false;
    }
}

/**
 * Display the mechanism preference widget.
 *
 * @param  string $key The preference key being displayed
 * @param  string $val The current preference value
 * @return string      HTML
 */
function smd_remote_mechanism($key, $val)
{
    $smd_remote_prefs = smd_remote_get_prefs();
    $vals = $smd_remote_prefs[$key]['content'];
    $current = do_list(get_pref($key, null));
    $out = array();

    foreach ($vals as $cb => $val) {
        $checked = in_array($cb, $current);
        $out[] = checkbox($key.'[]', $cb, $checked, '', $key.'.'.$cb) . sp . tag(gTxt($val), 'label', array('for' => $key.'.'.$cb));
    }

    return implode(n, $out);
}

/**
 * Display the privs selector preference widget.
 *
 * @param  string $key The preference key being displayed
 * @param  string $val The current preference value
 * @return string      HTML
 */
function smd_remote_privs($key, $val)
{
    $smd_remote_prefs = smd_remote_get_prefs();

    return selectInput($key, $smd_remote_prefs[$key]['content'][0], $val, $smd_remote_prefs[$key]['content'][1]);
}

/**
 * Plugin pref definitions.
 */
function smd_remote_get_prefs()
{
    static $smd_remote_prefs;

    if (!isset($smd_remote_prefs)) {
        $all = get_groups();
        ksort($all);
        array_shift($all);
        $all_bar_none = array_keys($all);

        $smd_remote_prefs = array(
            'smd_remote_mechanism' => array(
                'html'     => 'smd_remote_mechanism',
                'type'     => PREF_PLUGIN,
                'position' => 10,
                'content'  => array('remote' => 'smd_remote_internet', 'secure' => 'smd_remote_secure_loc'),
                'default'  => 'remote,secure',
            ),
            'smd_remote_secure_path' => array(
                'html'     => 'text_input',
                'type'     => PREF_PLUGIN,
                'position' => 20,
                'default'  => get_pref('path_to_site'),
            ),
            'smd_remote_download_status' => array(
                'html'     => 'text_input',
                'type'     => PREF_PLUGIN,
                'position' => 30,
                'default'  => '2',
            ),
            'smd_remote_limit_privs' => array(
                'html'     => 'smd_remote_privs',
                'type'     => PREF_PLUGIN,
                'position' => 40,
                'content'  => array($all + array(implode(',', $all_bar_none) => gTxt('smd_remote_all')), false),
                'default'  => '1',
            ),
        );
    }

    return $smd_remote_prefs;
}

// *****************************
// ******** PUBLIC TAGS ********
// *****************************
/**
 * Blow-by-blow equivalent of file_download_link, just a remote/secure aware version.
 *
 * Adds the choose attribute. Defaults to 0 (random, for load balancing).
 * Specify any higher integer to grab that particular entry from the file
 * (if it exists, else use 1st).
 *
 * @param  array  $atts  Tag attributes
 * @param  string $thing Tag contained content, if any
 * @return string        HTML
 *
 * @todo   Check this is in sync with latest 4.7 alterations.
 */
function smd_file_download_link($atts, $thing = null)
{
    global $thisfile;

    extract(lAtts(array(
        'filename'    => '',
        'id'          => '',
        'choose'      => '0',
        'show_link'   => '0', // Deprecated
        'show_suffix' => '0',
        'obfuscate'   => '0',
    ), $atts));

    if (isset($atts['show_link'])) {
        trigger_error(gTxt('deprecated_attribute', array('{name}' => 'show_suffix')), E_USER_NOTICE);
        $show_suffix = $show_link;
        unset($show_link);
    }

    // Remove the extra attributes not in the original tag
    unset($atts['choose']);
    unset($atts['show_suffix']);
    unset($atts['obfuscate']);

    $keys = array('smd_choose' => $choose);
    $out = file_download_link($atts, $thing);

    if (strpos($out, '.link') !== false) {
        $origLink = explode('"', $out);
        $idx = (count($origLink) == 1) ? 0 : 1;
        $origLink[$idx] = (($show_suffix) ? $origLink[$idx] : str_replace('.link', '', $origLink[$idx])) . join_qs($keys); // Will ignore join_qs if choose is 0
        $origLink[$idx] = ($obfuscate) ? dirname($origLink[$idx]) . '/' . substr(md5(basename($origLink[$idx])), 0, $obfuscate) : $origLink[$idx];
        $out = implode('"', $origLink);
    } else if (strpos($out, '.safe') !== false) {
        $out = ($show_suffix) ? $out : str_replace('.safe', '', $out);
    }

    return $out;
}

/**
 * Equivalent to file_download_name but optionally removes the .link/.safe suffix.
 *
 * @param  array  $atts Tag attributes
 * @return string       HTML
 *
 * @todo   Check this is in sync with latest 4.7 alterations.
 */
function smd_file_download_name($atts)
{
    global $thisfile;

    extract(lAtts(array(
        'show_link'   => '0',
        'show_suffix' => '0',
    ), $atts));

    assert_file();

    if (isset($atts['show_link'])) {
        trigger_error(gTxt('deprecated_attribute', array('{name}' => 'show_suffix')), E_USER_NOTICE);
        $show_suffix = $show_link;
        unset($show_link);
    }

    $out = $thisfile['filename'];

    if (!$show_suffix) {
        if (strpos($out, '.link') !== false) {
            $out = str_replace('.link', '', $out);
        } else if (strpos($out, '.safe') !== false) {
            $out = str_replace('.safe', '', $out);
        }
    }

    return $out;
}

/**
 * Add an image to the download form which, by default, is based on the filename of the download itself
 *
 * @param  array  $atts Tag attributes
 * @return string       HTML
 */
function smd_file_download_image($atts)
{
    global $thisfile;

    extract(lAtts(array(
        'filename'  => '',
        'id'        => '',
        'extension' => 'jpg',
        'ifmissing' => '?ref',
        'thumb'     => '0', // Deprecated
        'thumbnail' => '0',
        'class'     => '',
        'wraptag'   => '',
    ), $atts));

    if (isset($atts['thumb'])) {
        trigger_error(gTxt('deprecated_attribute', array('{name}' => 'thumbnail')), E_USER_NOTICE);
        $thumbnail = $thumb;
        unset($thumb);
    }

    if ($filename == '' && $id == '') {
        assert_file();
        $filename = $thisfile['filename'];
    }

    $filename = str_replace('.link', '', $filename) . (($extension == '') ? '' : '.' . $extension);

    $img = '';

    if ($id) {
        $img = ($thumbnail==0) ? @image(array('id' => $id, 'class' => $class, 'wraptag' => $wraptag)) : @thumbnail(array('id' => $id, 'class' => $class, 'wraptag' => $wraptag));
    } elseif ((strpos($filename, 'http://') === 0) || (strpos($filename, 'https://') === 0) || (strpos($filename, '/') === 0)) {
        $img = (($wraptag == '') ? '' : '<' . $wraptag . (($class == '') ? '' : ' class="' . $class . '"') .'>') . '<img src="' . $filename . '"' . (($wraptag == '' && $class) ? ' class="' . $class . '"' : '') . '/>'. (($wraptag == '') ? '' : '</' . $wraptag . '>');
    } else {
        $img = ($thumbnail==0) ? @image(array('name' => $filename, 'class' => $class, 'wraptag' => $wraptag)) : @thumbnail(array('name' => $filename, 'class' => $class, 'wraptag' => $wraptag));
    }

    $wrapper = (($wraptag=='') ? '@@REPL' : '<' . $wraptag . (($class == '') ? '' : ' class="' . $class . '"') .'>@@REPL</' . $wraptag . '>');

    if (strpos($ifmissing, '?ref') === 0) {
        $display = ($id) ? $id : $filename;
        $missing = str_replace('@@REPL', $display, $wrapper);
    } elseif (strpos($ifmissing, '?image') === 0) {
        $imgParts = explode(':', $ifmissing);

        if (count($imgParts) == 2) {
            $imgOpts = array();

            if (is_numeric($imgParts[1])) {
                $imgOpts['id'] = $imgParts[1];
            } else {
                $imgOpts['name'] = $imgParts[1];
            }

            $missing = str_replace('@@REPL', (($thumbnail == 0) ? @image($imgOpts) : @thumbnail($imgOpts)), $wrapper);
        } else {
            $missing = '';
        }
    } elseif ($ifmissing == '') {
        $missing = '';
    } else {
        $missing = str_replace('@@REPL', $ifmissing, $wrapper);
    }

    return ($img) ? $img : $missing;
}

/**
 * Check if the given file exists in the filesystem.
 *
 * @param  array  $atts  Tag attributes
 * @param  string $thing Tag contained content
 * @todo   Remove EvalElse().
 */
function smd_if_file_exists($atts, $thing)
{
    global $file_base_path;

    extract(lAtts(array(
        'filename' => '',
        'id'       => '',
        'path'     => '',
    ), $atts));

    if ($id) {
        $disfile = fileDownloadFetchInfo('id = '.intval($id));
        $filename = $disfile['filename'];
    }

    $path = ($path) ? $path : $file_base_path;
    $file_exists = file_exists(build_file_path($path, $filename));

    return parse(EvalElse($thing, $file_exists));
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_remote_file

Offers remote and secure file management through Textpattern's standard Files interface. This is very handy in two main situations:

# If you don't have the bandwidth available to host media content. Third party sites such as fileden.com offer the ability to upload fairly sizeable files and then freely hotlink to them (within quite generous bandwidth limits) so they can be shared and thus used within your Txp site
# If you want to lock down some files so they are not freely downloadable. While .htaccess can clamp down an entire directory or requires special rules for locking certain files, this plugin offers a more natural way to manage them by permitting access to only those people who have the authority to do so

h2(#features). Features

* Manage links to cloud-based files directly from the Txp Files tab, as if they were native
* Multiple sources for the same remote file are supported, for load balancing / bandwidth saving
* Manage files in non-web-accessible (i.e. secure) locations on your host server as if they were native files
* Files can optionally be served to logged-in users using Hidden / Pending status
* Integrates with smd_user_manager's privs system
* No modifications to Txp core / database required

h2. Installation / uninstallation

p(information). Requires Textpattern 4.7.2+

Download the plugin from either "textpattern.org":http://textpattern.org/plugins/901/smd_remote_file, or the "software page":http://stefdawson.com/sw, paste the code into the Txp _Admin->Plugins_ panel, install and enable the plugin. Visit the "forum thread":http://forum.textpattern.com/viewtopic.php?id=24673 for more info or to report on the success or otherwise of the plugin.

To remove the plugin, simply delete it from the _Admin->Plugins_ panel.

h2. Pre-requisites

For the remote file capability, choose any third party file hoster that offers free downloads of your stuff. Create an account, upload files to it, and make sure you know how to get Direct Link URLs from their interface (it's usually fairly obvious). You'll need these to paste into Textpattern.

For secure files you need to nominate a directory either a) outside of your web host's document root, or b) in a directory with an .htaccess file that forbids web access to any file. Configure the path to this location from the plugin preferences and then hop over to the _Files_ panel to upload your files.

h2. Plugin preferences

On the _Extensions->Remote file_ panel are the following options to govern how the plugin behaves:

; %Allow downloads from%
: Determines which interface elements you see. Choose from:
:: *The Internet* to allow remote URLs
:: *Secure location* to allow files to be stored in your nominated non-web-acessible folder
; %Secure file path%
: Absolute file path to the place you want to store secure files. Ignore this option if you have elected not to use secure files.
; %Permitted download statuses%
: List of status numbers (not names) for which you are going to permit downloads. Choose from:
:: 2 for Hidden files
:: 3 for Pending files
; %Limit downloads to this priv level%
: The privilege level of users who can download hidden/pending files (providing you have enbled this feature using the _Permitted download statuses_ pref). If you are using smd_user_manager this setting is ignored.

h2. The _Files_ tab: remote files

The plugin adds one input form field to the Files tab labelled _URL_. From your third party site(s) of choice, simply copy a web-friendly (i.e. url-encoded) hotlink and paste it into the URL box. You can usually tell if the link is url-encoded because it'll probably have @%20@ in place of any spaces in the filename. The directory part is, however, _not_ usually encoded: characters such as forward slashes and colons remain.

A typical file might look like this:

bc(block). http://www.fileden.com/files/2007/11/1/
     1234567/Here%20is%20some%20music.mp3

The link must be an absolute URL, beginning @http://@. Once you have pasted it in, hit Upload next to the box and a new special @.link@ file will be created in Txp. It takes on the filename exactly as it appears in your URL (with @.link@ added). So in the example above, a new file called @Here is some music.mp3.link@ will be made in your standard Textpattern files directory.

The new "file" will appear in the list just like any real file in Txp. You can edit it to add a title, description, category, set its status: everything you can do with a conventional file. Just don't rename it!

h3. Multiple personalities

Third party sites don't give you something for nothing; they normally have a bandwidth cap, just like your web hoster might enforce. If you want to distribute your music or latest video, a few thousand hits per month will eat all your available bandwidth.

So spread the load around the Internet. Get accounts at various third party sites and upload exactly the same file (make sure the filenames are identical -- including caSe SensITivItY). Then just paste the URLs into the upload box: smd_remote_file will only maintain one physical file within Txp but will hold details of your other copies for you. Alternatively, click to edit the @.link@ file from the _Files_ panel and paste the other URLs inside the Remote URLs text area; one URL per line. Using either the standard "file_download_link tag":http://textpattern.net/wiki/index.php?title=file_download_link or the new "smd_file_download_link tag":#smd_rem_fdl will randomly pick one of your download locations associated with each file every time the page loads, spreading your bandwidth usage.

h2. The _Files_ tab: secure files

The plugin adds two checkboxes alongside the create / upload boxes, both labelled @Secure@. When you create a Txp file from an already uploaded physical file, or upload a new file, just check the checkbox to store the file in your nominated secure location. A corresponding @.safe@ file will be created in the regular @files@ directory containing meta data about the file. At the moment this meta data just houses some placeholder text and if you click a .safe file to edit it you will see a box labelled _Secure options_ into which you can write whatever you like. Just be aware that this area is reserved for future use.

When editing either a @.safe@ or regular file you may replace it with another using the upload box at the bottom of the _File edit_ panel. It is automatically ticked if the file you are editing is secure, but you may uncheck it to convert the new uploaded file to a regular file, and vice versa. Note that even if the uploaded file has a different filename to the one already uploaded, Txp will maintain the original filename just as it does for regular files.

h2. The _Files_ tab: hidden/pending files

The plugin allows a third method of delivering files utilising the status bits. By default, Txp forbids downloads of any files that are not Live (status=4). But if you nominate a status of 2 (Hidden) or 3 (Pending) in the plugin preference _Permitted download statuses_ you can then upload regular files to Txp's interface and set their status to one of the other options.

A regular @<txp:file_download_link>@ tag will skip non-live files, but you can add the @status@ attribute to display the hidden content. Clicking on such a link will still, however fail to download the file unless *you are logged in* and one of the following conditions is met:

* You are the author (uploader) of the file
* Your privilege level matches that given by the _Limit downloads to this priv level_ preference
* smd_user_manager is installed and the @file.download@ priv area includes the privilege level of your account
* smd_user_manager is installed and the @file.download.status_number@ priv area includes the privilege level of your account

This is an ideal scenario where wrapping the @<txp:file_download_link>@ with some credentials checking plugin/tag (e.g. rvm_privileged, smd_um_has_privs, cbe_frontauth_protect, etc) can help to deliver content only to special members.

h2(tag #smd_rem_fdl). Tag: @<txp:smd_file_download_link>@

An exact drop-in replacement for the standard "file_download_link tag":http://textpattern.net/wiki/index.php?title=file_download_link tag, with a few extra attributes:

; %id%
: The ID of the file you want to link to. If left blank, it can be supplied from whatever is between the opening and closing tag
; %filename%
: The filename of the file you want to link to. If left blank, it can be supplied from whatever is between the opening and closing tag. If both filename and ID are specified, ID takes precedence
; %choose%
: %(important)(for remote URLs only)% governs how to choose which remote URL to serve. Set it to 0 to randomly pick a URL from those uploaded for this file (the standard file_download_link tag will also do this). You can also specify a higher number to pick the URL from that particular slot. So @choose="1"@ will always select the 1st file you uploaded and deliver that; @choose="2"@ the second; and so on. If you specify a number bigger than the number of URLs stored against a file, it picks the first one you uploaded.
; %show_suffix%
: Whether to display the @.link@ or @.safe@ suffix in the link. Choose from:
:: 0 to hide the suffix
:: 1 to show the suffix (in other words, make the tag behave like the built-in @file_download_link@)
: Default: 0
; %obfuscate%
: %(important)(for remote URLs only)% hide the filename portion of the linkand replace it with a random string of characters. Specify the length of the string, e.g. @obfusctae="8"@ would render a link that resembles @http://site.com/file_download/42/3e9845ac@. This is handy to keep the filename partly secret, but it's not foolproof. As soon as someone starts downloading the file the full remote URL is known.

h2(tag #smd_rem_fdn). Tag: @<txp:smd_file_download_name>@

An exact drop-in replacement for the standard "file_download_name":http://textpattern.net/wiki/index.php?title=file_download_name tag, but with one attribute:

; %show_suffix%
: Whether to display the @.link@ or @.safe@ on the end of file name. Choose from:
:: 0 to hide the suffix
:: 1 to display it (thus behaving like the built-in @file_download_name@)

h2(tag #smd_rem_fdi). Tag: @<txp:smd_file_download_image>@

When linking to external content (especially media files) it is often useful to make a mini image to go with it, such as a still from a movie or some artwork for an mp3 track. You can of course embed a @<txp:image>@ tag in your download form, but that will give a static image for each file. This tag can be used to display images that vary with the filename of the remote, secure or regular file.

To use it, just upload an image (by default a jpg) via Txp's _Images_ panel and give it the exact same filename as the remote file it represents, plus its normal image file extension. i.e. if your remote file was @Man and boy.mpg@ you would upload an image and name it @Man and boy.mpg.jpg@. Do this for each file and then use this tag to display them.

By default, if any image doesn't exist, the tag outputs the image filename instead (if using the @filename@) or the id (if using the @id@). This behaviour can be overridden with the @ifmissing@ option.

; %id%
: The ID of an image to display
; %filename%
: The filename of an image to display. If both filename and ID are specified, ID takes precedence. Note that in this and @id@ modes, the tag is essentially the same as @<txp:image>@. The exception is that you do not have to specify the image file @extension@, as it does that for you by default if you use JPGs and you can specify thumbnails instead using the @thumbnail@ attribute
; %extension%
: Saves you having to specify the file extension in the @filename@ parameter. Enter it here _without_ the dot.
: Default: @jpg@
; %thumbnail%
: Display full size image or thumbnail. Choose from:
:: 0 for the full size image
:: 1 if you have created thumbnails and wish to use them
: Default: 0
; %ifmissing%
: Governs what to do in the event an image is missing. Use @ifmissing=""@ to output nothing in the event of a missing image. Other alternatives:
:: @?ref@ to display either the image filename or its ID if it was used as an attribute.
:: @?image:ID_or_name"@ to display the given Txp image (e.g. @ifmissing="?image:32"@ or @ifmissing="?image:NoPic.jpg"@)
:: @some_text@ to display the given text, e.g. @ifmissing="No image yet"@
; %wraptag%
: The HTML tag to wrap around the outside of the image. Specify it with no angle brackets, e.g. @wraptag="p"@.
; %class%
: The CSS class name to apply to the image. If using @wraptag@ the class is applied to the surrounding tag. If it is omitted the class is applied directly to the image.

h2. How it works

For the curious, the plugin just creates a placeholder text file with the name of your file plus the special suffix @.link@ or @.safe@ to distinguish it from a standard file. The contents of the file determine which URLs to consider when downloading, or the special options that apply to this secure file (to be decided in a future version).

For remote files, shuffling the order of URLs alters the effect of the @choose@ attribute. Just make sure you have one URL per line that *must* begin with @http@ and *must* have the same base filename as the corresponding @.link@ file.

Incidentally, the real file size is fetched every time the local file is edited because otherwise Txp overwrites it with the size of the placeholder text file. In the case of remote files, it reads the file size of the first URL. This has potential ramifications when downloading because _the plugin checks that the remote file size matches the one in the Txp interface before serving the file_. If one of your uploaded files is a different size it will refuse to download.

The reason for this is to try to maintain download count integrity. Instead of dishing out a standard 404 message, some servers will redirect to an image or HTML file to tell you that a file is missing. This returns a status code of '200 OK' to indicate that the download of the replacement content went ok, but in this case we do _not_ want to increment the counter; the file's still missing after all! Under rare circumstances you might find that the provider returns content exactly the same length as the file itself and the count would then be wrongly incremented. Practically, your file is going to be larger than their replacement so it won't matter, but if it causes problems, shuffle the order of the URLs so a provider you trust not to use such tactics is first in the list.

Note that the 'download' link next to each file on the Files tab of the admin interface always chooses a random file from those uploaded with that name.

h2. Examples

h3(#smd_rem_eg1). Example 1: standard download

In your @files@ form:

bc(block). <txp:file_download_link>
 <txp:smd_file_download_name /> [<txp:file_download_size
     format="auto" decimals="2" />]
</txp:file_download_link>

h3(#smd_rem_eg2). Example 2: specific download

bc(block). <txp:smd_file_download_link choose="2"
     show_suffix="1" />

Will always select the 2nd URL from those uploaded for each file. Shows the @.link@ on the end of each remote file.

h3(#smd_rem_eg3). Example 3: using an image to download file

bc(block). <txp:smd_file_download_link>
 <txp:smd_file_download_image ifmissing="Sorry, no image found"
     wraptag="span" class="dload" />
</txp:smd_file_download_link>

Displays an image with the same name (plus .jpg) as the remote file. The image is clickable to allow the file to be downloaded but if the image is not found, the text "Sorry, no image found" will be displayed instead (the text is also clickable). Wraps the img or text in @<span>@ tags with a class of @dload@.

h3(#smd_rem_eg4). Example 4: hidden downloads

TODO

h2. Author and credits

Written by "Stef Dawson":http://stefdawson.com/contact. For other software by me, or to make a donation, see the "software page":http://stefdawson.com/sw.

I cannot possibly claim all the credit for this hunk of code. The plugin would not have existed if it weren't for the amazing mind of Ruud van Melick. He suggested a very clever solution to my remote file predicament. I built on that, extended it, refined it, pluginised it and gave it to you. Many thanks to Ruud for the awesome support he offers the community, and also to Wet for his assistance with helping me understand the core.
# --- END PLUGIN HELP ---
-->
<?php
}
?>