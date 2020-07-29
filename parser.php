<?php

error_reporting(E_ERROR);

$showBranch  = !in_array("-H", $argv);
$writeReport = in_array("-o", $argv);
$days = ($index = array_search("-d", $argv)) !== false ? $argv[$index+1] : 1;
$days++;

if ($showBranch) {
    $last200BranchesCommited = explode(PHP_EOL, `git for-each-ref --format='%(refname:short)' --no-merged origin/master --sort=-authordate refs/remotes/ | head -n 100`);
    array_unshift($last200BranchesCommited, "origin/master");

    foreach ($last200BranchesCommited as $branch) {
        foreach (explode(PHP_EOL, PHP_EOL . `git log -100 --pretty=format:%h $branch`) as $commit) {

            if ($commitsByBranch['origin/master'][$commit])
                continue;

            $commitsByBranch[$branch][$commit] = true;
            $branchesByCommit[$commit][]       = $branch;
        }
    }
}

$findCommitLine = false;
while(!feof(STDIN)){

    if ($forcedLine) {
        $line = $forcedLine;
        $forcedLine = null;
    } else {
        $line = fgets(STDIN);
    }

    $matchedCommitLine = preg_match_all("#^([a-z0-9]{7,11}) - (.*) \((.*?) - (.*?) - (.*?)\) <(.*?)> #s", $line, $matches);
    !$findCommitLine && !$matchedCommitLine && $matchedFileLine =  preg_match_all("#^diff --git a/(.*?) b/(.*?)#", $line, $matches);

    //if ($after && $commitCode == "63e13babbd4") {
     //   echo $line;
    //}

    if ($matchedCommitLine) {

        $findCommitLine = false;
        $commitCode    = $matches[1][0];
        $commitMessage = $matches[2][0];
        $commitRelTime = $matches[3][0];
        $commitTime    = $matches[4][0];
        $commitUnix    = $matches[5][0];
        $commitAuthor  = "<" .$matches[6][0] . ">";

        if (preg_match("#(\d+) days? ago#", $commitRelTime, $matchesTime)) {
            if ($matchesTime[1] >= $days) {
                $findCommitLine = true;

                continue;
            }
        } elseif (preg_match("#(\d+) months? ago#", $commitRelTime, $matchesTime)) {
            $findCommitLine = true;
            continue;
        }

        if ($showBranch) {
            $commitMessage = join(", ", $branchesByCommit[$commitCode]) . " - " . $commitMessage;
        }


    } elseif ($matchedFileLine) {

        //if ($commitCode != "s") {
        //    continue;
        //}

        $fileName         = $matches[1][0];
        $fileNameNew      = $matches[2][0];

        $addedLines   = 0;
        $deletedLines = 0;
        $commentLines = 0;
        $modifiedLines = 0;
        $addedLinesInARow = 0;
        $deletedLinesInARow = 0;

        $binaryFile = false;
        $generatedFile = false;
        while(!feof(STDIN)) {
            $line = fgets(STDIN);

            $matchedCommitLine = preg_match_all("#^([a-z0-9]{7,11}) - (.*?) \((.*?)\) <(.*?)> #s", $line, $matches);
            !$matchedCommitLine && $matchedFileLine =  preg_match_all("#^diff --git a/(.*?) b/(.*?)#", $line, $matches);

            //if ($commitCode == "63e13babbd4") {
            //    echo $line;
            //    echo $addedLines;
            //    echo $deletedLines;
            //    echo $modifiedLines;
            //    echo $commentLines;
            //}

            if ($matchedCommitLine || $matchedFileLine) {
               // $after = 1;
                $forcedLine = $line;
                break;
            }

            $matchedDiffLine =  preg_match_all("#^([-+]){1}([^+-]*?)#", $line, $matches);
            $matchedGitLog = (0 === strpos($line, "--- ")) || (0 === strpos($line, "+++ "));

            if (!$matchedDiffLine || $matchedGitLog) {
                $addedLinesInARow = 0;
                $deletedLinesInARow = 0;
                continue;
            }


            if (preg_match("#(.*?)(\.min\.js|\.css|\.svg|\.json)$|(.*?)/build(.*?)(\.js|\.css)#i", $fileName)) {
                $generatedFile = true;
                continue;
            }

            if (preg_match("#(.*?)(\.po|\.mo)$#", $fileName)) {
                $binaryFile = true;
                continue;
            }

            if (trim(substr($line, 1)) === "") {
                continue;
            }

            //echo $fileName . " " . $line;
            $commentLine = false;
            if (preg_match("#(.*?)(.js|\.php)$#i", $fileName)) {
                if ($matches[1][0] == "+") {
                    if (preg_match("#^\s*(<\?php\s+)?(\/\/|\/\*|\*\/|\*)#si", substr($line, 1))) {
                        $commentLine = true;
                    }
                }
            }

            if ($commentLine) {
                $commentLines++;
            } elseif ($matches[1][0] == "+") {
                $addedLines++;
                $addedLinesInARow++;

                if ($deletedLinesInARow) {
                    $modifiedLines++;
                    $deletedLines--;
                    $deletedLinesInARow--;
                    $addedLines--;
                    $addedLinesInARow--;
                }

            } elseif ($matches[1][0] == "-") {
                $deletedLines++;
                $deletedLinesInARow++;

                if ($addedLinesInARow) {
                    $modifiedLines++;
                    $addedLines--;
                    $addedLinesInARow--;
                    $deletedLines--;
                    $deletedLinesInARow--;
                }
            }

            if (strpos("Binary", $line) === 0) {
                $binaryFile = true;
            }
        }


        $totalLines = $modifiedLines + $addedLines + $deletedLines;

        $data[$commitAuthor] = $data[$commitAuthor] ?? [
            'files' => [],
            'binary_files' => [],
            'generated_files' => [],
            'commits' => [],
            'summary_lines' => [],
            'summary_files' => [],
        ];

        $summaryLines = [];

        $modifiedLines && $summaryLines[] = "$modifiedLines modified";
        $addedLines && $summaryLines[] = "$addedLines added";
        $deletedLines && $summaryLines[] = "$deletedLines deleted";
        $commentLines && $summaryLines[] = "$commentLines commented";

        $summaryLinesTxt = $summaryLines ?  " (" . join(", ", $summaryLines) . ")" : "";
        $data[$commitAuthor]['files'] = $data[$commitAuthor]['files'] ?? [];

        !$generatedFile && $data[$commitAuthor]['files'][$fileName] += $totalLines;
        $generatedFile && $data[$commitAuthor]['generated_files'][$fileName] += $totalLines;
        $binaryFile && $data[$commitAuthor]['binary_files'][$fileName]++;

        $commitFiles = &$data[$commitAuthor]['commits']["$commitUnix - $commitCode - $commitMessage - $commitRelTime - $commitTime"];
        $commitFiles = $commitFiles ?? [];

        if (!$generatedFile) {
            $commitFiles[] = "$fileName" . " ----$summaryLinesTxt --H[$commitCode]";
        }

        $data[$commitAuthor]['summary_lines']['modified'] += $modifiedLines;
        $data[$commitAuthor]['summary_lines']['added'] += $addedLines;
        $data[$commitAuthor]['summary_lines']['deleted'] += $deletedLines;
        $data[$commitAuthor]['summary_lines']['commented'] += $commentLines;

        $data[$commitAuthor]['summary_files']['files'] = count($data[$commitAuthor]['files']);
        $data[$commitAuthor]['summary_files']['autogenerated'] = count($data[$commitAuthor]['generated_files']);
        $data[$commitAuthor]['summary_files']['binary'] = count($data[$commitAuthor]['binary_files']);
    }

}

// Reverse commits
foreach ($data as $author => &$dataAuthor) {
    ksort($dataAuthor['commits']);
}

if (empty($data)) {
    exit;
}

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// echo $json; exit; //PURE JSON

$json = preg_replace([
        "#\"\d+ - ([a-f0-9]{7,11}) - (.*?) - (.*?) - (.*?)\":#s",
        //"#\"(.*?) ---- #s",
       // "#(--H\[.*?\])\".*?,#s",
        "#(\"<(?:.*?)>\")#s",
        "#(\d+ commented|.commented.*?\d+)#s",
        "#(\d+ added|.added\".*?\d+)#s",
        "#(\d+ modified|.modified.*?\d+)#s",
        "#(\d+ deleted|.deleted.*?\d+)#s",
    ], [
            "\"\033[1;31m$1\033[0m - \033[6;33m$2\033[0m - \033[1;34m$3\033[0m - \033[1;32m$4\033[0m\":",
       // "\"\033[1;37m$1\033[0m ---- ",
       // "\",\033[0;30m-$1\033[0m",
        "\033[1;37m$1\033[0m",
        "\033[0;37m$1\033[0m",
        "\033[0;32m$1\033[0m",
        "\033[0;35m$1\033[0m",
        "\033[0;31m$1\033[0m",
    ], $json);
echo $json;

if (!$writeReport) {
    exit;
}

$canvasDataArray = [];
foreach ($data as $author => $dataAuthor) {

    $title = $author;
    $subtitle = "whatever";
    $axisY = "Number of lines";

    $graphData = [];
    foreach ($dataAuthor['summary_lines'] as $type => $number) {
        $graphData[] = ['label' => $type, 'y' => $number];
    }

    $graphDataJson = json_encode($graphData, JSON_NUMERIC_CHECK);

    $canvasDataArray[] = [
        'id' => time() ."-". rand(99999,999999),
        'title' => $title,
        'subtitle' => $subtitle,
        'axisY' => $axisY,
        'data' => $graphDataJson,
    ];

    $i++;
}


if ($i == 0){
    exit;
}

ob_start();

?>

<!DOCTYPE HTML>
<html>
<head>
    <script src="https://canvasjs.com/assets/script/canvasjs.min.js"></script>
</head>
<body>

<script>
    function toggleDataSeries(e) {
        if (typeof (e.dataSeries.visible) === "undefined" || e.dataSeries.visible) {
            e.dataSeries.visible = false;
        } else {
            e.dataSeries.visible = true;
        }
        e.chart.render();
    }
</script>
    <script>

        window.addEventListener('load', function () {

            var data = [];
            <?php foreach($canvasDataArray as $canvasData): ?>
                data.push({
                    type: "stackedColumn",
                    yValueFormatString: "# lines",
                    name: "<?= $canvasData['title'] ?>",
                    dataPoints: <?= $canvasData['data'] ?>,
                    showInLegend: true
                });
            <?php endforeach; ?>

            var chart = new CanvasJS.Chart("all", {
                title: {
                    text: "Cumulative line changes",
                    theme: "light2",
                    animationEnabled: true,
                },
                toolTip: {
                    shared: true,
                    reversed: true
                },
                subtitles: [{
                    text: ""
                }],
                axisY: {
                    title: "Lines Changed",
                    includeZero: true
                },
                legend: {
                    cursor: "pointer",
                    itemclick: toggleDataSeries
                },
                data: data
            });
            chart.render();

        }, false);

    </script>
    <div id="all" style="height: 500px; width: 100%;"></div>
</body>
</html>

<?php
$contents = ob_get_clean();

file_put_contents('git-report-index.html', $contents);
