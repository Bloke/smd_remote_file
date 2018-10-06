# Changelog

## 0.50 - 2012-10-11

* Major rewrite.
* Added secure file support.
* Added hidden/pending file downloads (thanks Shaun).
* Fixed token javascript error.
* Added `obfuscate` attribute.
* Renamed `show_link` as `show_suffix`.
* Renamed `thumb` as `thumbnail`.

## 0.43 - 2008-06-10

* Fixed spurious characters in the single tag version of `smd_file_download_link` (thanks iblastoff).

## 0.42 - 2008-04-09

* Fixed minor edge case bug in `smd_file_download_link`.

## 0.41 - 2008-04-02

* Added `smd_file_download_name` (thanks aswihart).

## 0.40 - 2008-02-03

* Requires Textpattern 4.0.6.
* Removed error check from v0.31.
* Added/improved replace remote file.
* Reduced timeout for accessing remote URL to try and prevent ugly warnings.

## 0.32 - 2007-12-12

* Enhanced `ifmissing` to include image support.
* Changed the `?file` attribute to `?ref` (it's a better name).

## 0.31 - 2007-12-05

* File is checked for error condition prior to download in line with core changeset r2720 (thanks Mary) - can be removed when 4.0.6 is released.

## 0.30 - 2007-12-04

* Removed `.link` when using `smd_file_download_link`.
* Added `show_link` attribute.
* Improved error handling code.
* Download count only increases if file sizes match.

## 0.20 - 2007-12-04

* Added download counter and some better status messages.

## 0.10 - 2007-11-12

* Initial public release.
