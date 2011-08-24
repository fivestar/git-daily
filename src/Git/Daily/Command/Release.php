    protected $base_branch = 'develop';

    protected $merge_to = array('master', 'develop');

    protected $branch_prefix = 'release';

    /**
     * getMergedBranches
     */
    protected function getMergeBranches()
    {
        return $this->merge_to;
    }

        if ($current_branch != $this->config[$this->base_branch]) {
                "currently not on {$this->config[$this->base_branch]} but on $current_branch"
        $release_branches = Git_Daily_GitUtil::releaseBranches($this->branch_prefix);
                array('grep', array("remotes/$remote/" . $this->branch_prefix))
        $new_release_branch = $this->branch_prefix . '/' . date('Ymd-Hi');
        // merge current branch
        if (isset($this->config['remote'])) {
            //
            // Fetch --all is already done. Just git merge.
            //
            $remote = $this->config['remote'];
            self::info("merge $current_branch branch from remote");
            $res = self::cmd(Git_Daily::$git, array('merge', "$remote/$current_branch"));
            if (self::$last_command_retval != 0) {
                self::warn('merge failed');
                self::outLn($res);
                throw new Git_Daily_Exception('abort');
            }
        }

        $develop_branch = $this->config[$this->base_branch];
        $release_branches = Git_Daily_GitUtil::releaseBranches($this->branch_prefix);
            array('grep', array("remotes/$remote/{$this->branch_prefix}"),
                    'sed', array('s/^[^a-zA-Z0-9]*//g'),
        $release_branches = Git_Daily_GitUtil::releaseBranches($this->branch_prefix);
        if (empty($release_branches)) {
            throw new Git_Daily_Exception("{$this->branch_prefix} branch not found. abort.");
        $release_branch = array_shift($release_branches);
        //
        // Start merging diffs
        //
        foreach ($this->getMergeBranches() as $branch_name) {
            $merge_branches = self::cmd(Git_Daily::$git, array('branch'),
                array(
                    'grep', array($branch_name),
                    array('sed', array('s/^[^a-zA-Z0-9]*//g')
                    )
                )
            );
            $merge_branch = array_shift($merge_branches);
            $remote = $this->config['remote'];
            
            if (empty($merge_branch)) {
                continue;
            if (!empty($remote)) {
                self::info('first, fetch remotes');
                self::cmd(Git_Daily::$git, array('fetch', '--all'));
                self::info('diff check');
                
                $diff_branch_str1 = "{$release_branch}..{$remote}/{$release_branch}";
                $diff_branch_str2 = "{$remote}/{$release_branch}..{$release_branch}";
                $res1 = self::cmd(Git_Daily::$git, array('diff', $diff_branch_str1));
                $res2 = self::cmd(Git_Daily::$git, array('diff', $diff_branch_str2));
                if (!empty($res1) || !empty($res2)) {
                    self::warn("There are some diff between local and $remote, run release sync first.");
                    throw new Git_Daily_Exception('abort');
                }
            self::info("checkout {$merge_branch} and merge $release_branch to {$merge_branch}");
            self::cmd(Git_Daily::$git, array('checkout', $merge_branch));
            // pull
            if (!empty($remote)) {
                list($res, $retval) = Git_Daily_CommandUtil::cmd(Git_Daily::$git, array('daily', 'pull'));
                if ($retval != 0) {
                    self::warn("{$merge_branch} pull failed");
                    throw new Git_Daily_Exception('abort');
                }
           
            // merged check
            $res = Git_Daily_GitUtil::mergedBranches();
            if (!in_array($release_branch, $res)) {
                // merge to master
                list($res, $retval) = Git_Daily_CommandUtil::cmd(Git_Daily::$git, array('merge', '--no-ff', $release_branch));
                self::outLn($res);
                if ($retval != 0) {
                    self::warn('merge failed');
                    throw new Git_Daily_Exception('abort');
                }
            // push the merged branch to remote
            self::info("push $merge_branch to $remote");
            self::cmd(Git_Daily::$git, array('checkout', $merge_branch));
            list($res, $retval) = Git_Daily_CommandUtil::cmd(Git_Daily::$git, array('push', $remote, $merge_branch));
        self::cmd(Git_Daily::$git, array('checkout', $this->config[$this->base_branch]));
        return "{$this->branch_prefix} closed";
    }

    private function _doList()
    {
        $release_branch = Git_Daily_GitUtil::releaseBranches($this->branch_prefix);
        if (empty($release_branch)) {
            throw new Git_Daily_Exception("{$this->branch_prefix} branch not found. abort.");
        }
        $current_branch = Git_Daily_GitUtil::currentBranch();
        $master_branch = $this->config['master'];

        //
        // check if remote has release process
        //
        if (isset($this->config['remote'])) {
            self::info('first, fetch remotes');
            self::cmd(Git_Daily::$git, array('fetch', '--all'));
        }
        
        //
        // Get revision list using git rev-list.
        //
        $revision_list = array();
        $revision_id_list = self::cmd(Git_Daily::$git, array('rev-list', "$master_branch..$current_branch"));
        foreach ($revision_id_list as $rev_id) {
            //
            // Get the detail of a revision using git show.
            //
            $logs = self::cmd(Git_Daily::$git, array('show', $rev_id));

            $revision = array();
            $revision['id'] = $rev_id;
            $revision['files'] = array();
            $revision['files']['added'] = array();
            $revision['files']['modified'] = array();

            //
            // Parse output of git show.
            //
            $merge = false;
            foreach ($logs as $line) {
                if (preg_match("/^Merge: /", $line)) {
                    $merge = true;
                } elseif (preg_match("/^Author: .+\<([^@]+)@([^>]+)>/", $line, $matches)) {
                    $revision['author'] = $matches[1];
                } elseif (preg_match("/^diff --git a\/([^ ]+) /", $line, $matches)) {
                    $file = $matches[1];
                } elseif (preg_match("/^new file mode/", $line)) {
                    $revision['files']['added'][] = $file;
                } elseif (preg_match("/^index/", $line)) {
                    $revision['files']['modified'][] = $file;
                }
            }

            //
            // Skip a merge log.
            //
            if (!$merge) {
                $revision_list[] = $revision;
            }
        }

        //
        // Merge file list
        //
        $modlist = array();
        $addlist = array();
        foreach ($revision_list as $revision) {
            $modlist = array_merge($modlist, $revision['files']['modified']);
            $addlist = array_merge($addlist, $revision['files']['added']);
        }
        sort($modlist);
        sort($addlist);
        $modlist = array_unique($modlist);
        $addlist = array_unique($addlist);

        //
        // Print commit list
        //
        if (count($revision_list) > 0) {
            self::outLn("Commit list:");
            foreach ($revision_list as $revision) {
                self::outLn("\t" . $revision['id'] . " = " . $revision['author']);
            }
            self::outLn("");
        }

        //
        // Print added files
        //
        if (count($addlist) > 0) {
            self::outLn("Added files:");
            foreach ($addlist as $file) {
                self::outLn("\t$file");
            }
            self::outLn("");
        }

        //
        // Print modified files
        //
        if (count($modlist) > 0) {
            self::outLn("Modified files:");
            foreach ($modlist as $file) {
                self::outLn("\t$file");
            }
            self::outLn("");
        }

        //
        // Print URL list by author when gitdaily.logurl is define.
        //
        if (isset($this->config['logurl']) && count($revision_list) > 0) {
            // Merge commit list by author
            $author_list = array();
            foreach ($revision_list as $revision) {
                if (!isset($author_list[$revision['author']])) {
                    $author_list[$revision['author']] = array();
                }

                // Add commit list
                $author_list[$revision['author']][] = $revision['id'];
            }

            self::outLn("Author list:");
            foreach ($author_list as $author => $id_list) {
                self::outLn("\t@$author:");
                foreach ($id_list as $id) {
                    $url = sprintf("\t{$this->config['logurl']}", $id);
                    self::outLn($url);
                }
                self::outLn("");
            }
        }
   or: git daily release list        : Show release list