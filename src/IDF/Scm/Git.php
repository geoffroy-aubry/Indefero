<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2008 Céondo Ltd and contributors.
#
# InDefero is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# InDefero is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
#
# ***** END LICENSE BLOCK ***** */

/**
 * Git utils.
 *
 */
class IDF_Scm_Git
{
    public $repo = '';
    public $mediumtree_fmt = 'commit %H%nAuthor: %an <%ae>%nTree: %T%nDate: %ai%n%n%s%n%n%b';
    
    public function __construct($repo)
    {
        $this->repo = $repo;
    }

    /**
     * Returns the URL of the git daemon.
     *
     * @param IDF_Project
     * @return string URL
     */
    public static function getRemoteAccessUrl($project)
    {
        $url = Pluf::f('git_remote_url');
        if (Pluf::f('git_repositories_unique', true)) {
            return $url;
        }
        return $url.'/'.$project->shortname.'.git';
    }

    /**
     * Returns this object correctly initialized for the project.
     *
     * @param IDF_Project
     * @return IDF_Scm_Git
     */
    public static function factory($project)
    {
        $rep = Pluf::f('git_repositories');
        if (false == Pluf::f('git_repositories_unique', false)) {
            $rep = $rep.'/'.$project->shortname.'.git';
        }
        return new IDF_Scm_Git($rep);
    }

    /**
     * Test a given object hash.
     *
     * @param string Object hash.
     * @param null to be svn client compatible
     * @return mixed false if not valid or 'blob', 'tree', 'commit'
     */
    public function testHash($hash, $dummy=null)
    {
        $cmd = sprintf('GIT_DIR=%s git cat-file -t %s',
                       escapeshellarg($this->repo),
                       escapeshellarg($hash));
        $ret = 0; $out = array();
        IDF_Scm::exec($cmd, &$out, &$ret);
        if ($ret != 0) return false;
        return trim($out[0]);
    }

    /**
     * Given a commit hash returns an array of files in it.
     *
     * A file is a class with the following properties:
     *
     * 'perm', 'type', 'size', 'hash', 'file'
     *
     * @param string Commit ('HEAD')
     * @param string Base folder ('')
     * @return array 
     */
    public function filesAtCommit($commit='HEAD', $folder='')
    {
        // now we grab the info about this commit including its tree.
        $co = $this->getCommit($commit);
        if ($folder) {
            // As we are limiting to a given folder, we need to find
            // the tree corresponding to this folder.
            $found = false;
            foreach ($this->getTreeInfo($co->tree) as $file) {
                if ($file->type == 'tree' and $file->file == $folder) {
                    $found = true;
                    $tree = $file->hash;
                    break;
                }
            }
            if (!$found) {
                throw new Exception(sprintf(__('Folder %1$s not found in commit %2$s.'), $folder, $commit));
            }
        } else {
            $tree = $co->tree;
        }
        $res = array();
        // get the raw log corresponding to this commit to find the
        // origin of each file.
        $rawlog = array();
        $cmd = sprintf('GIT_DIR=%s git log --raw --abbrev=40 --pretty=oneline %s',
                       escapeshellarg($this->repo), escapeshellarg($commit));
        IDF_Scm::exec($cmd, &$rawlog);
        // We reverse the log to be able to use a fixed efficient
        // regex without back tracking.
        $rawlog = implode("\n", array_reverse($rawlog));
        foreach ($this->getTreeInfo($tree, false) as $file) {
            // Now we grab the files in the current tree with as much
            // information as possible.
            $matches = array();
            if ($file->type == 'blob' and preg_match('/^\:\d{6} \d{6} [0-9a-f]{40} '.$file->hash.' .*^([0-9a-f]{40})/msU',
                           $rawlog, &$matches)) {
                $fc = $this->getCommit($matches[1]);
                $file->date = $fc->date;
                $file->log = $fc->title;
                $file->author = $fc->author;
            } else if ($file->type == 'blob') {
                $file->date = $co->date;
                $file->log = $co->title;
                $file->author = $co->author; // May be wrong in some cases.
            }
            $file->fullpath = ($folder) ? $folder.'/'.$file->file : $file->file;
            $res[] = $file;
        }
        return $res;
    }

    /**
     * Get the tree info.
     *
     * @param string Tree hash 
     * @param bool Do we recurse in subtrees (true)
     * @return array Array of file information.
     */
    public function getTreeInfo($tree, $recurse=true)
    {
        if ('tree' != $this->testHash($tree)) {
            throw new Exception(sprintf(__('Not a valid tree: %s.'), $tree));
        }
        $cmd_tmpl = 'GIT_DIR=%s git-ls-tree%s -t -l %s';
        $cmd = sprintf($cmd_tmpl, 
                       escapeshellarg($this->repo), 
                       ($recurse) ? ' -r' : '',
                       escapeshellarg($tree));
        $out = array();
        $res = array();
        IDF_Scm::exec($cmd, &$out);
        foreach ($out as $line) {
            list($perm, $type, $hash, $size, $file) = preg_split('/ |\t/', $line, 5, PREG_SPLIT_NO_EMPTY);
            $res[] = (object) array('perm' => $perm, 'type' => $type, 
                                    'size' => $size, 'hash' => $hash, 
                                    'file' => $file);
        }
        return $res;
    }


    /**
     * Get the file info.
     *
     * @param string File
     * @param string Commit ('HEAD')
     * @return false Information
     */
    public function getFileInfo($totest, $commit='HEAD')
    {
        $cmd_tmpl = 'GIT_DIR=%s git-ls-tree -r -t -l %s';
        $cmd = sprintf($cmd_tmpl, 
                       escapeshellarg($this->repo), 
                       escapeshellarg($commit));
        $out = array();
        IDF_Scm::exec($cmd, &$out);
        foreach ($out as $line) {
            list($perm, $type, $hash, $size, $file) = preg_split('/ |\t/', $line, 5, PREG_SPLIT_NO_EMPTY);
            if ($totest == $file) {
                return (object) array('perm' => $perm, 'type' => $type, 
                                      'size' => $size, 'hash' => $hash, 
                                      'file' => $file);
            }
        }
        return false;
    }

    /**
     * Get a blob.
     *
     * @param string request_file_info
     * @param null to be svn client compatible
     * @return string Raw blob
     */
    public function getBlob($request_file_info, $dummy=null)
    {
        return IDF_Scm::shell_exec(sprintf('GIT_DIR=%s git-cat-file blob %s',
                                    escapeshellarg($this->repo), 
                                    escapeshellarg($request_file_info->hash)));
    }

    /**
     * Get the branches.
     *
     * @return array Branches.
     */
    public function getBranches()
    {
        $out = array();
        IDF_Scm::exec(sprintf('GIT_DIR=%s git branch', 
                              escapeshellarg($this->repo)), &$out);
        $res = array();
        foreach ($out as $b) {
            $res[] = substr($b, 2);
        }
        return $res;
    }

    /**
     * Get commit details.
     *
     * @param string Commit ('HEAD').
     * @return array Changes.
     */
    public function getCommit($commit='HEAD')
    {
        $cmd = sprintf('GIT_DIR=%s git show --date=iso --pretty=format:%s %s',
                       escapeshellarg($this->repo), 
                       "'".$this->mediumtree_fmt."'", 
                       escapeshellarg($commit));
        $out = array();
        IDF_Scm::exec($cmd, &$out);
        $log = array();
        $change = array();
        $inchange = false;
        foreach ($out as $line) {
            if (!$inchange and 0 === strpos($line, 'diff --git a')) {
                $inchange = true;
            }
            if ($inchange) {
                $change[] = $line;
            } else {
                $log[] = $line;
            }
        }
        $out = self::parseLog($log, 4);
        $out[0]->changes = implode("\n", $change);
        return $out[0];
    }


    /**
     * Get latest changes.
     *
     * @param string Commit ('HEAD').
     * @param int Number of changes (10).
     * @return array Changes.
     */
    public function getChangeLog($commit='HEAD', $n=10)
    {
        if ($n === null) $n = '';
        else $n = ' -'.$n;
        $cmd = sprintf('GIT_DIR=%s git log%s --date=iso --pretty=format:\'%s\' %s',
                       escapeshellarg($this->repo), $n, $this->mediumtree_fmt, 
                       escapeshellarg($commit));
        $out = array();
        IDF_Scm::exec($cmd, &$out);
        return self::parseLog($out, 4);
    }

    /**
     * Parse the log lines of a --pretty=medium log output.
     *
     * @param array Lines.
     * @param int Number of lines in the headers (3)
     * @return array Change log.
     */
    public static function parseLog($lines, $hdrs=3)
    {
        $res = array();
        $c = array();
        $i = 0;
        $hdrs += 2;
        foreach ($lines as $line) {
            $i++;
            if (0 === strpos($line, 'commit')) {
                if (count($c) > 0) {
                    $c['full_message'] = trim($c['full_message']);
                    $res[] = (object) $c;
                }
                $c = array();
                $c['commit'] = trim(substr($line, 7));
                $c['full_message'] = '';
                $i=1;
                continue;
            }
            if ($i == $hdrs) {
                $c['title'] = trim($line);
                continue;
            }
            $match = array();
            if (preg_match('/(\S+)\s*:\s*(.*)/', $line, $match)) {
                $match[1] = strtolower($match[1]);
                $c[$match[1]] = trim($match[2]);
                if ($match[1] == 'date') {
                    $c['date'] = gmdate('Y-m-d H:i:s', strtotime($match[2]));
                }
                continue;
            }
            if ($i > ($hdrs+1)) {
                $c['full_message'] .= trim($line)."\n";
                continue;
            }
        }
        $c['full_message'] = trim($c['full_message']);
        
        $res[] = (object) $c;
        return $res;
    }

    /**
     * Generate the command to create a zip archive at a given commit.
     *
     * @param string Commit
     * @param string Prefix ('git-repo-dump')
     * @return string Command
     */
    public function getArchiveCommand($commit, $prefix='git-repo-dump/')
    {
        return sprintf('GIT_DIR=%s git archive --format=zip --prefix=%s %s',
                       escapeshellarg($this->repo),
                       escapeshellarg($prefix),
                       escapeshellarg($commit));
    }

}