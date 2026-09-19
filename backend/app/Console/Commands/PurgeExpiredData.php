<?php

namespace App\Console\Commands;

use App\Models\BehaviorBaseline;
use App\Models\BehaviorEvent;
use App\Models\DataDeletionRequest;
use App\Models\SessionReport;
use App\Models\TherapySession;
use App\Models\TranscriptSegment;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Retention enforcement. Scheduled hourly (routes/console.php):
 *  - executes due data_deletion_requests (consent withdrawal, account deletion)
 *  - deletes behaviour events / transcripts older than the configured retention
 *  - drops expired verification codes
 */
class PurgeExpiredData extends Command
{
    protected $signature = 'ravan:purge {--dry-run}';

    protected $description = 'Apply retention policy and pending deletion requests';

    public function handle(AuditLogger $audit): int
    {
        $dry = $this->option('dry-run');
        $n = 0;
        foreach (DataDeletionRequest::where('status', 'pending')->where('scheduled_for', '<=', now())->get() as $req) {
            if ($dry) {
                $this->line("would delete: {$req->scope} for user {$req->user_id} session {$req->therapy_session_id}");

                continue;
            }
            if ($req->scope === 'session_derived' && $req->therapy_session_id) {
                $n += BehaviorEvent::where('therapy_session_id', $req->therapy_session_id)->delete();
                $n += BehaviorBaseline::where('therapy_session_id', $req->therapy_session_id)->delete();
                SessionReport::where('therapy_session_id', $req->therapy_session_id)->delete();
            } elseif ($req->scope === 'all_sessions' || $req->scope === 'account') {
                $n += BehaviorEvent::where('patient_id', $req->user_id)->delete();
                $sessions = TherapySession::where('patient_id', $req->user_id)->pluck('id');
                $n += TranscriptSegment::whereIn('therapy_session_id', $sessions)->delete();
                BehaviorBaseline::whereIn('therapy_session_id', $sessions)->delete();
                SessionReport::whereIn('therapy_session_id', $sessions)->delete();
                if ($req->scope === 'account') {
                    User::find($req->user_id)?->delete();
                }
            }
            $req->update(['status' => 'completed', 'completed_at' => now()]);
            $audit->log(null, 'retention.deletion_request.completed', $req, ['rows' => $n]);
        }

        $eventDays = config('ravan.retention.behavior_events_days');
        $trDays = config('ravan.retention.transcripts_days');
        if (! $dry && $eventDays) {
            $n += BehaviorEvent::where('created_at', '<', now()->subDays((int) $eventDays))->delete();
        }
        if (! $dry && $trDays) {
            $n += TranscriptSegment::where('created_at', '<', now()->subDays((int) $trDays))->delete();
        }
        VerificationCode::where('expires_at', '<', now()->subDay())->delete();
        $this->info("purged {$n} rows");

        return self::SUCCESS;
    }
}
