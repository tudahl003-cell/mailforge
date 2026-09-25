<?php
// worker.php — long-running campaign job queue consumer.
// Launched by entrypoint.sh as a side-process next to Apache.
// Polls data/jobs/ for *.json, runs the campaign, writes result
// to data/results/<id>.json, and deletes the job file.
// One campaign at a time; jobs for the same campaign are de-duped.
require_once __DIR__ . '/include/mail.php';

$jobsDir = DATA_DIR . '/jobs';
$resDir  = DATA_DIR . '/results';
@mkdir($jobsDir, 0775, true);
@mkdir($resDir, 0775, true);

fwrite(STDERR, "[worker] started pid=" . getmypid() . "\n");

// single-instance guard
$lock = fopen(DATA_DIR . '/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[worker] another worker holds the lock; exiting\n");
    exit(0);
}

while (true) {
    $files = glob($jobsDir . '/*.json');
    if ($files) {
        sort($files); // FIFO
        $f = array_shift($files);
        $job = json_decode((string)@file_get_contents($f), true);
        if (is_array($job) && !empty($job['id'])) {
            $id = (int)$job['id'];
            $lockFile = $jobsDir . '/.running_' . $id . '.lock';
            if (!file_exists($lockFile)) {
                @file_put_contents($lockFile, (string)getmypid(), LOCK_EX);
                fwrite(STDERR, "[worker] running campaign #$id\n");
                try {
                    $summary = run_campaign($id, $job['opts'] ?? []);
                    @file_put_contents($resDir . '/' . $id . '.json',
                        json_encode(['ts' => now(), 'summary' => $summary], JSON_UNESCAPED_SLASHES));
                    // mark done via campaign.status already set in run_campaign
                } catch (Throwable $e) {
                    @file_put_contents($resDir . '/' . $id . '.json',
                        json_encode(['ts' => now(), 'summary' =>
                            ['ok' => false, 'error' => $e->getMessage()]]));
                }
                @unlink($lockFile);
                fwrite(STDERR, "[worker] done campaign #$id\n");
            }
        }
        @unlink($f); // consume
    } else {
        sleep(1);
    }
}
