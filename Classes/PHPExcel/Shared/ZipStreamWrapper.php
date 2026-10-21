<?php
/**
 * PHPExcel
 *
 * Copyright (c) 2006 - 2014 PHPExcel
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category   PHPExcel
 * @package    PHPExcel_Shared
 * @copyright  Copyright (c) 2006 - 2014 PHPExcel (http://www.codeplex.com/PHPExcel)
 * @license    http://www.gnu.org/licenses/old-licenses/lgpl-2.1.txt	LGPL
 * @version    ##VERSION##, ##DATE##
 */


/**
 * PHPExcel_Shared_ZipStreamWrapper
 *
 * @category   PHPExcel
 * @package    PHPExcel_Shared
 * @copyright  Copyright (c) 2006 - 2014 PHPExcel (http://www.codeplex.com/PHPExcel)
 */
class PHPExcel_Shared_ZipStreamWrapper {
	/** @var resource|null Stream context supplied by PHP. */
	public $context;
	/**
	 * Internal ZipArchive
	 *
	 * @var ZipArchive
	 */
    private $_archive;

    /**
     * Filename in ZipArchive
     *
     * @var string
     */
    private $_fileNameInArchive = '';

    /**
     * Position in file
     *
     * @var int
     */
    private $_position = 0;

    /**
     * Data
     *
     * @var mixed
     */
    private $_data = '';

    /**
     * Register wrapper
     */
    public static function register() {
		@stream_wrapper_unregister("zip");
		@stream_wrapper_register("zip", __CLASS__);
    }

    /**
	 * Implements support for fopen().
	 *
	 * @param	string	$path			resource name including scheme, e.g.
	 * @param	string	$mode			only "r" is supported
	 * @param	int		$options		mask of STREAM_REPORT_ERRORS and STREAM_USE_PATH
	 * @param	string  &$openedPath	absolute path of the opened stream (out parameter)
	 * @return	bool    true on success
     */
    public function stream_open($path, $mode, $options, &$opened_path) {
        // Check for mode
        if ($mode[0] != 'r') {
            throw new PHPExcel_Reader_Exception('Mode ' . $mode . ' is not supported. Only read mode is supported.');
        }

		$pos = strrpos($path, '#');
		if ($pos === false) {
			return false;
		}
		$url['host'] = substr($path, 6, $pos - 6); // 6: strlen('zip://')
		$url['fragment'] = substr($path, $pos + 1);

        // Open archive
        $this->_archive = new ZipArchive();
        if ($this->_archive->open($url['host']) !== true) {
            $this->_archive = null;
            return false;
        }

        $this->_fileNameInArchive = $url['fragment'];
        $this->_position = 0;
        $this->_data = $this->_archive->getFromName( $this->_fileNameInArchive );
        if ($this->_data === false) {
            $this->stream_close();
            return false;
        }
        $opened_path = $path;

        return true;
    }

    /**
	 * Implements support for fstat().
	 *
	 * @return  string
     */
    public function statName() {
        return $this->_fileNameInArchive;
    }

    /** Retrieve metadata without requiring an already-open stream. */
    public function url_stat($path, $flags): array|false {
        $separator = strrpos($path, '#');
        if ($separator === false) {
            return false;
        }
        $archive = new ZipArchive();
        if ($archive->open(substr($path, 6, $separator - 6)) !== true) {
            return false;
        }
        $name = substr($path, $separator + 1);
        try {
            return self::entryStat($archive->statName($name), $name);
        } finally {
            $archive->close();
        }
    }

    /** Convert ZIP entry metadata to the standard stat() structure. */
    private static function entryStat($entry, $name): array|false {
        if ($entry === false) {
            return false;
        }
        $stat = array_fill_keys(array(
            'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev',
            'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks',
        ), 0);
        $stat['mode'] = str_ends_with($name, '/') ? 0040555 : 0100444;
        $stat['nlink'] = 1;
        $stat['size'] = $entry['size'];
        $stat['mtime'] = $entry['mtime'];
        return array_merge(array_values($stat), $stat);
    }

    /**
	 * Implements support for fstat().
	 *
	 * @return  array|false
     */
    public function stream_stat() {
        if ($this->_archive === null) {
            return false;
        }
        return self::entryStat($this->_archive->statName($this->_fileNameInArchive), $this->_fileNameInArchive);
    }

    public function stream_close() {
        if ($this->_archive !== null) {
            $this->_archive->close();
            $this->_archive = null;
        }
    }

    /**
	 * Implements support for fread(), fgets() etc.
	 *
	 * @param   int		$count	maximum number of bytes to read
	 * @return  string
     */
    function stream_read($count) {
        $ret = substr($this->_data, $this->_position, $count);
        $this->_position += strlen($ret);
        return $ret;
    }

    /**
	 * Returns the position of the file pointer, i.e. its offset into the file
	 * stream. Implements support for ftell().
	 *
	 * @return  int
     */
    public function stream_tell() {
        return $this->_position;
    }

    /**
     * EOF stream
	 *
	 * @return	bool
     */
    public function stream_eof() {
        return $this->_position >= strlen($this->_data);
    }

    /**
     * Seek stream
	 *
	 * @param	int		$offset	byte offset
	 * @param	int		$whence	SEEK_SET, SEEK_CUR or SEEK_END
	 * @return	bool
     */
    public function stream_seek($offset, $whence) {
        $length = strlen($this->_data);
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->_position + $offset,
            SEEK_END => $length + $offset,
            default => -1,
        };
        if ($position < 0 || $position > $length) {
            return false;
        }
        $this->_position = $position;
        return true;
    }
}
