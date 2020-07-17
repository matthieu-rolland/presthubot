<?php
namespace Console\App\Command;

use Console\App\Command\GithubCheckModuleCommand;
use Console\App\Service\Github;
use Console\App\Service\Github\Filters;
use Console\App\Service\Github\Query;
use Console\App\Service\PrestaShop\ModuleChecker;
use Console\App\Service\PrestaShop\NightlyBoard;
use Console\App\Service\Slack;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
 
class SlackNotifierCommand extends Command
{
    /**
     * @var Github;
     */
    protected $github;
    /**
     * @var ModuleChecker;
     */
    protected $moduleChecker;
    /**
     * @var NightlyBoard;
     */
    protected $nightlyBoard;
    /**
     * @var Slack;
     */
    protected $slack;
    /**
     * @var string;
     */
    protected $slackChannelCore;
    /**
     * @var string;
     */
    protected $slackChannelQA;

    /**
     * @var string
     */
    private const CACHE_CHECKSTATSQA = '.cache/slacknotifier_checkStatsQA.json';

    protected function configure()
    {
        $this->setName('slack:notifier')
            ->setDescription('Notify Teams on Slack every day')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['GH_TOKEN'] ?? null
            )
            ->addOption(
                'slacktoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['SLACK_TOKEN'] ?? null
            )
            ->addOption(
                'slackchannelCore',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['SLACK_CHANNEL_CORE'] ?? null
            )
            ->addOption(
                'slackchannelQA',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['SLACK_CHANNEL_QA'] ?? null
            );   
    }
 
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Local Variable
        $slackMessageCore = $slackMessageQA = [];

        $this->github = new Github($input->getOption('ghtoken'));
        $this->moduleChecker = new ModuleChecker($this->github);
        $this->nightlyBoard = new NightlyBoard();
        $this->slack = new Slack($input->getOption('slacktoken'));
        $this->slackChannelCore = $input->getOption('slackchannelCore');
        $this->slackChannelQA = $input->getOption('slackchannelQA');

        $title = ':preston::date: Welcome to the PrestHubot Report of the day :date:';
        $slackMessageCore[] = $title;
        $slackMessageQA[] = $title;
        
        // Check Status
        $statusNightly = $this->checkStatusNightly();
        $slackMessageCore[] = $statusNightly;
        $slackMessageQA[] = $statusNightly;

        // Check if PR are need to merge
        $slackMessageCore[] = $this->checkPRReadyToMerge();

        // Check PR to review
        $slackMessageCore[] = $this->checkPRReadyToReview();

        // Need module releases
        $slackMessageCore[] = $this->checkModuleReadyToRelease();

        // Need module improvements
        $slackMessageCore[] = $this->checkModuleImprovements();

        // Check QA Stats
        $slackMessageQA[] = $this->checkStatsQA();

        foreach ($slackMessageCore as $message) {
            $this->slack->sendNotification($this->slackChannelCore, $message);
        }
        foreach ($slackMessageQA as $message) {
            $this->slack->sendNotification($this->slackChannelQA, $message);
        }
    }

    protected function checkStatusNightly(): string
    {
        $report177x = $this->nightlyBoard->getReport(date('Y-m-d'), '1.7.7.x');
        $reportDevelop = $this->nightlyBoard->getReport(date('Y-m-d'), 'develop');

        $has177XPassed = isset($report177x['tests'], $report177x['tests']['passed']);
        $has177XFailed = isset($report177x['tests'], $report177x['tests']['failed']);
        $has177XPending = isset($report177x['tests'], $report177x['tests']['pending']);
        $hasDevelopPassed = isset($reportDevelop['tests'], $reportDevelop['tests']['passed']);
        $hasDevelopFailed = isset($reportDevelop['tests'], $reportDevelop['tests']['failed']);
        $hasDevelopPending = isset($reportDevelop['tests'], $reportDevelop['tests']['pending']);
        
        $status177X = ($has177XFailed && $report177x['tests']['failed'] == 0);
        $statusDevelop = ($hasDevelopFailed && $reportDevelop['tests']['failed'] == 0);
        
        $emoji177X = $status177X ? ':greenlight:' : ':redlight:';
        $emojiDevelop = $statusDevelop ? ':greenlight:' : ':redlight:';
        
        $slackMessage = ':notebook_with_decorative_cover: Nightly Board :notebook_with_decorative_cover:' . PHP_EOL;
        $slackMessage .= ' - <https://nightly.prestashop.com/report/'.$report177x['id'].'|'.$emoji177X.' Report `1.7.7.x`>';
        $slackMessage .= ' : ';
        $slackMessage .= $has177XPassed ? ':heavy_check_mark: ' . $report177x['tests']['passed'] : '';
        $slackMessage .= ($has177XPassed && ($has177XFailed || $has177XPending) ? ' - ' : '');
        $slackMessage .= $has177XFailed ? ':x: ' . $report177x['tests']['failed'] : '';
        $slackMessage .= (($has177XPassed || $has177XFailed) && ($has177XPending) ? ' - ' : '');
        $slackMessage .= $has177XPending ? '⏸️ ' . $report177x['tests']['pending'] : '';
        $slackMessage .= PHP_EOL;
        $slackMessage .= ' - <https://nightly.prestashop.com/report/'.$reportDevelop['id'].'|'.$emojiDevelop.' Report `develop`>';
        $slackMessage .= ' : ';
        $slackMessage .= $hasDevelopPassed ? ':heavy_check_mark: ' . $reportDevelop['tests']['passed'] : '';
        $slackMessage .= ($hasDevelopPassed && ($hasDevelopFailed || $hasDevelopPending) ? ' - ' : '');
        $slackMessage .= $hasDevelopFailed ? ':x: ' . $reportDevelop['tests']['failed'] : '';
        $slackMessage .= (($hasDevelopPassed || $hasDevelopFailed) && ($hasDevelopPending) ? ' - ' : '');
        $slackMessage .= $hasDevelopPending ? '⏸️ ' . $reportDevelop['tests']['pending'] : '';
        $slackMessage .= PHP_EOL;
        return $slackMessage;
    }

    protected function checkPRReadyToMerge(): string
    {
        $requests = Query::getRequests();
        $graphQLQuery = new Query();
        $graphQLQuery->setQuery('org:PrestaShop is:pr ' . $requests[Query::REQUEST_PR_WAITING_FOR_MERGE]);
        $prReadyToMerge = $this->github->search($graphQLQuery);
        if (!empty($prReadyToMerge)) {
            $slackMessage = ':rocket: PR Ready to Merge :rocket:' . PHP_EOL;
            foreach ($prReadyToMerge as $pullRequest) {
                $pullRequest = $pullRequest['node'];
                $slackMessage .= ' - <'.$pullRequest['url'].'|:preston: '.$pullRequest['repository']['name'].'#'.$pullRequest['number'] .'>'
                    .' : '.$pullRequest['title'] . PHP_EOL;
            }
            return $slackMessage;
        }
        return '';
    }

    protected function checkPRReadyToReview(): string
    {
        $requests = Query::getRequests();
        $graphQLQuery = new Query();
        $graphQLQuery->setQuery('org:PrestaShop is:pr ' . $requests[Query::REQUEST_PR_WAITING_FOR_REVIEW]);
        $prReviews = $this->github->search($graphQLQuery);
        $prReadyToReview = [];
        $filters = new Filters();
        $filters->addFilter(Filters::FILTER_REPOSITORY_PRIVATE, [false], true);
        // 1st PR with already a review (indicate who has ever)
        // 2nd PR without review
        foreach ([5,4,3,2,1,0] as $numApproved) {
            $filters->addFilter(Filters::FILTER_NUM_APPROVED, [$numApproved], true);
            foreach ($prReviews as $pullRequest) {
                $pullRequest = $pullRequest['node'];
                $pullRequest['approved'] = $this->github->extractApproved($pullRequest);
                if (!$this->github->isPRValid($pullRequest, $filters)) {
                    continue;
                }
                $prReadyToReview[] = $pullRequest;
            }
            if (count($prReadyToReview) >= 10) {
                break;
            }
        }
        if (!empty($prReadyToReview)) {
            $prReadyToReview = array_slice($prReadyToReview, 0, 10);
            $slackMessage = ':eyes: PR Ready to Review :eyes:' . PHP_EOL;
            foreach ($prReadyToReview as $pullRequest) {
                $slackMessage .= ' - <'.$pullRequest['url'].'|:preston: '.$pullRequest['repository']['name'].'#'.$pullRequest['number'] .'>'
                    .' : '.$pullRequest['title'];
                if (!empty($pullRequest['approved'])) {
                    $slackMessage .= PHP_EOL;
                    $slackMessage .= '    - :heavy_check_mark: ' . implode(', ', $pullRequest['approved']);
                }
                $slackMessage .= PHP_EOL;
                $slackMessage .= PHP_EOL;
            }
            $slackMessage = $this->slack->linkGithubUsername($slackMessage);
            return $slackMessage;
        }
        return '';
    }

    protected function checkModuleReadyToRelease(): string
    {
        $modulesNeedRelease = [];
        foreach (GithubCheckModuleCommand::REPOSITORIES as $repository) {
            $checkBranches = $this->moduleChecker->checkBranches('PrestaShop', $repository);
            if ($checkBranches['hasDiffMaster']) {
                $modulesNeedRelease[$repository] = $checkBranches['status'];
            }
        }
        uasort($modulesNeedRelease, function($a, $b) {
            if ($a['numPRMerged'] == $b['numPRMerged']) {
                if ($a['ahead'] == $b['ahead']) {
                    return 0;
                }
                return ($a['ahead'] > $b['ahead']) ? -1 : 1;
            }
            return ($a['numPRMerged'] > $b['numPRMerged']) ? -1 : 1;
        });
        if (!empty($modulesNeedRelease)) {
            $modulesNeedRelease = array_slice($modulesNeedRelease, 0, 10);
            $slackMessage = ':rocket: Modules need some release :rocket:' . PHP_EOL;
            foreach ($modulesNeedRelease as $repository => $status) {
                $slackMessage .= ' - <https://github.com/PrestaShop/'.$repository.'|:preston: '.$repository.'> ';
                $slackMessage .= '('.$status['ahead'].' commits / '.$status['numPRMerged'].' PR'.($status['numPRMerged'] > 1 ? 's': '').')';
                $slackMessage .= PHP_EOL;
            }
            return $slackMessage;
        }
        return '';
    }

    protected function checkModuleImprovements(): string
    {
        $improvements = [];
        foreach (GithubCheckModuleCommand::REPOSITORIES as $repository) {
            if (count($improvements) > 10) {
                break;
            }
            $this->moduleChecker->resetChecker();
            $this->moduleChecker->checkRepository('PrestaShop', $repository);
            if ($this->moduleChecker->getRating(ModuleChecker::RATING_GLOBAL) == ModuleChecker::RATING_GLOBAL_MAX) {
                continue;
            }
            $report = $this->moduleChecker->getReport();
            if ($report['archived'] || $report['moved']) {
                $improvements[] = '<https://github.com/PrestaShop/'.$repository.'|:preston: '.$repository.'> Please remove for the Presthubot analysis';
                continue;
            }
            
            if ($this->moduleChecker->getRating(ModuleChecker::RATING_BRANCH) != ModuleChecker::RATING_BRANCH_MAX) {
                // Needs release (cf. checkModuleReadyToRelease)
            }
            if ($this->moduleChecker->getRating(ModuleChecker::RATING_DESCRIPTION) != ModuleChecker::RATING_DESCRIPTION_MAX) {
                $improvements[] = '<https://github.com/PrestaShop/'.$repository.'|:preston: '.$repository.'> Please description ' . $path;
            }
            if ($this->moduleChecker->getRating(ModuleChecker::RATING_FILES) != ModuleChecker::RATING_FILES_MAX) {
                foreach ($report['files'] as $path => $check) {
                    $allCheck = true;
                    foreach ($check as $key => $value) {
                        $allCheck = $allCheck && $value;
                    }
                    if ($allCheck) {
                        continue;
                    }
                    if (!$check[ModuleChecker::CHECK_FILES_EXIST]) {
                        $improvements[] = '<https://github.com/PrestaShop/'.$repository.'|:preston: '.$repository.'> Missing file ' . $path;
                    }
                    if (isset($check[ModuleChecker::CHECK_FILES_CONTAIN])) {
                        $remainingCheck = true;
                        foreach ($check as $key => $value) {
                            if ($check == ModuleChecker::CHECK_FILES_EXIST) {
                                continue;
                            }
                            $remainingCheck = $remainingCheck && $value;
                        }
                        if (!$remainingCheck) {
                            $improvements[] = '<https://github.com/PrestaShop/'.$repository.'|:preston: '.$repository.'> Invalid file ' . $path;
                        }
                    }
                }
            }
            if ($this->moduleChecker->getRating(ModuleChecker::RATING_ISSUES) != ModuleChecker::RATING_ISSUES_MAX) {
                $improvements[] = '<https://github.com/PrestaShop/'.$repository.'|:preston: '.$repository.'> Please migrate issues to main repository and close issues on the module repository';
            }
            if ($this->moduleChecker->getRating(ModuleChecker::RATING_LABELS) != ModuleChecker::RATING_LABELS_MAX) {
                $improvements[] = '<https://github.com/PrestaShop/'.$repository.'|:preston: '.$repository.'> Please fix labels ' . $path;
            }
            if ($this->moduleChecker->getRating(ModuleChecker::RATING_LICENSE) != ModuleChecker::RATING_LICENSE_MAX) {
                $improvements[] = '<https://github.com/PrestaShop/'.$repository.'|:preston: '.$repository.'> Invalid license (Check composer.json)';
            }
            if ($this->moduleChecker->getRating(ModuleChecker::RATING_TOPICS) != ModuleChecker::RATING_TOPICS_MAX) {
                $topics = '';
                foreach ($report['githubTopics'] as $key => $value) {
                    if (!$value) {
                        $topics .= (empty($topics) ? $key : ', ' . $key);
                    }
                }
                $improvements[] = '<https://github.com/PrestaShop/'.$repository.'|:preston: '.$repository.'> Please add missings topics on Github (' . $topics . ')';
            }
        }
        if (!empty($improvements)) {
            $improvements = array_slice($improvements, 0, 10);
            $slackMessage = ':pencil: Modules need some improvements :pencil:' . PHP_EOL;
            foreach ($improvements as $message) {
                $slackMessage .= ' - ' . $message .' (on master branch)' . PHP_EOL;
            }
            return $slackMessage;
        }
        return '';
    }

    protected function checkStatsQA(): string
    {
        $graphQLQuery = new Query();
        $slackMessage = ':chart_with_upwards_trend: PR Stats for QA :chart_with_upwards_trend:' . PHP_EOL;

        $searchPR176 = 'repo:PrestaShop/PrestaShop is:pr is:open label:1.7.6.x '.Query::LABEL_WAITING_FOR_QA.' -'.Query::LABEL_WAITING_FOR_AUTHOR;
        $graphQLQuery->setQuery($searchPR176);
        $countPR176 = $this->github->countSearch($graphQLQuery);

        $searchPR177 = 'repo:PrestaShop/PrestaShop is:pr is:open label:1.7.7.x '.Query::LABEL_WAITING_FOR_QA.' -'.Query::LABEL_WAITING_FOR_AUTHOR;
        $graphQLQuery->setQuery($searchPR177);
        $countPR177 = $this->github->countSearch($graphQLQuery);

        $searchPRDevelop = 'repo:PrestaShop/PrestaShop is:pr is:open -label:1.7.7.x -label:1.7.6.x '.Query::LABEL_WAITING_FOR_QA.' -'.Query::LABEL_WAITING_FOR_AUTHOR;
        $graphQLQuery->setQuery($searchPRDevelop);
        $countDevelop = $this->github->countSearch($graphQLQuery);

        $searchPRModules = 'org:PrestaShop -repo:PrestaShop/PrestaShop is:pr is:open '.Query::LABEL_WAITING_FOR_QA.' -'.Query::LABEL_WAITING_FOR_AUTHOR;
        $graphQLQuery->setQuery($searchPRModules);
        $countModules = $this->github->countSearch($graphQLQuery);

        $searchPRWaitingForAuthor = 'org:PrestaShop is:pr is:open '.Query::LABEL_WAITING_FOR_QA.' '.Query::LABEL_WAITING_FOR_AUTHOR;
        $graphQLQuery->setQuery($searchPRWaitingForAuthor);
        $countWaitingForAuthor = $this->github->countSearch($graphQLQuery);

        // Cache
        $cache = [];
        if (is_file(self::CACHE_CHECKSTATSQA)) {
            $cache = file_get_contents(self::CACHE_CHECKSTATSQA);
            $cache = json_decode($cache, true);
        }
        $cache[date('Y-m-d')] = [
            '176' => $countPR176,
            '177' => $countPR177,
            'Develop' => $countDevelop,
            'Modules' => $countModules,
            'WaitingForAuthor' => $countWaitingForAuthor,
        ];
        if(!is_dir(\dirname(self::CACHE_CHECKSTATSQA))) {
            \mkdir(\dirname(self::CACHE_CHECKSTATSQA));
        }
        \file_put_contents(self::CACHE_CHECKSTATSQA, \json_encode($cache));
        
        // Stats
        $dateJSub1 = date('D') == 'Mon' ? date('Y-m-d', strtotime('-3 days')) : date('Y-m-d', strtotime('-1 day'));
        
        $countPR176Diff = isset($cache[$dateJSub1], $cache[$dateJSub1]['176'])
            ? ' ('.(($cache[$dateJSub1]['176'] - $countPR176) == 0 ? '=' : $cache[$dateJSub1]['176'] - $countPR176) .')'
            : '';
        $countPR177Diff = isset($cache[$dateJSub1], $cache[$dateJSub1]['177'])
            ? ' ('.(($cache[$dateJSub1]['177'] - $countPR177) == 0 ? '=' : $cache[$dateJSub1]['177'] - $countPR177) .')'
            : '';
        $countDevelopDiff = isset($cache[$dateJSub1], $cache[$dateJSub1]['Develop'])
            ? ' ('.(($cache[$dateJSub1]['Develop'] - $countDevelop) == 0 ? '=' : $cache[$dateJSub1]['Develop'] - $countDevelop) .')'
            : '';
        $countModulesDiff = isset($cache[$dateJSub1], $cache[$dateJSub1]['Modules'])
            ? ' ('.(($cache[$dateJSub1]['Modules'] - $countModules) == 0 ? '=' : $cache[$dateJSub1]['Modules'] - $countModules) .')'
            : '';
        $countWaitingForAuthorDiff = isset($cache[$dateJSub1], $cache[$dateJSub1]['WaitingForAuthor'])
            ? '('.(($cache[$dateJSub1]['WaitingForAuthor'] - $countWaitingForAuthor) == 0 ? '=' : $cache[$dateJSub1]['WaitingForAuthor'] - $countWaitingForAuthor) .')'
            : '';

        // Number of PR with the label "Waiting for QA", without the label "Waiting for author", filtered by branch
        $slackMessage .= '- <https://github.com/search?q='.urlencode(stripslashes($searchPR176)).'|PR 1.7.6.x> : *' . $countPR176 . '*' . $countPR176Diff . PHP_EOL;
        $slackMessage .= '- <https://github.com/search?q='.urlencode(stripslashes($searchPR177)).'|PR 1.7.7.x> : *' . $countPR177 . '*' . $countPR177Diff . PHP_EOL;
        $slackMessage .= '- <https://github.com/search?q='.urlencode(stripslashes($searchPRDevelop)).'|PR Develop> : *' . $countDevelop . '*' . $countDevelopDiff . PHP_EOL;
        // Number of PR for Modules
        $slackMessage .= '- <https://github.com/search?q='.urlencode(stripslashes($searchPRModules)).'|PR Modules> : *' . $countModules . '*' . $countModulesDiff . PHP_EOL;
        // Number of PR with the label "Waiting for QA" AND with the label "Waiting for author"
        $slackMessage .= '- <https://github.com/search?q='.urlencode(stripslashes($searchPRWaitingForAuthor)).'|PR Waiting for Author> : *' . $countWaitingForAuthor . '*' . $countWaitingForAuthorDiff . PHP_EOL;
        return $slackMessage;
    }
}