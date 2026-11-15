<?php
declare(strict_types=1);
namespace CajeerBook;

final class AutomationView
{
    public static function render(array $db,string $csrf,string $notice='',array $query=[]):string{
        $e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$a=$db['automation']??[];$c=$a['config']??null;$git=$db['github']??null;
        $url=$e(WorkspaceUrls::url('/studio/automation'));$hidden='<input type="hidden" name="csrf" value="'.$e($csrf).'">';
        $html='<h1>Webhooks &amp; Automation</h1><p>Configure authenticated push and merged PR/MR webhooks and metadata-only outgoing events. The worker imports safe Git changes as drafts; conflicts require manual resolution.</p>';
        if($notice!=='')$html.='<p class="notice" role="status">'.$e($notice).'</p>';
        $html.='<p><a href="'.$e(WorkspaceUrls::url('/studio/git')).'">Git Sync</a> · <a href="'.$e(WorkspaceUrls::url('/studio')).'">Studio</a></p>';
        if($git){$provider=$git['provider']??'github';$html.='<h2>Incoming '.$e($provider).' webhook</h2><p>Use your installation origin followed by <code>'.$e(WorkspaceUrls::url('/webhooks/'.$provider)).'</code>. Select push and optionally pull/merge request events; use JSON. The secret must match below. Bound to '.$e($git['repo']).' / '.$e($git['branch']).'.</p>';}
        else $html.='<p>Connect a Git repository to enable incoming push webhooks.</p>';
        $html.='<p>Current configuration: '.($c?'Enabled':'Disabled').'. Secrets are never displayed. Replacing configuration cancels pending work; supply both secrets again for enabled directions.</p><form method="post" action="'.$url.'">'.$hidden.'<input type="hidden" name="expected" value="'.$e($c['id']??'').'">';
        $html.='<label>Incoming secret (leave empty to disable)<input name="incoming" type="password" autocomplete="new-password" maxlength="128" '.(!$git?'disabled':'').'></label><label>Outgoing HTTPS destination (empty to disable)<input name="destination" type="url" value="'.$e($c['url']??'').'" maxlength="2048"></label><label>Outgoing secret<input name="outgoing" type="password" autocomplete="new-password" maxlength="128"></label><fieldset><legend>Outgoing events</legend>';
        foreach(AutomationQueue::EVENTS as $event)$html.='<label><input type="checkbox" name="events[]" value="'.$e($event).'" '.(in_array($event,$c['events']??[],true)?'checked':'').'> '.$e($event).'</label>';
        $html.='</fieldset><button name="action" value="configure">Replace configuration</button></form><h2>Job queue</h2><p>Run <code>php bin/worker.php --loop</code> as the application user. Failed jobs can be retried after resolving the problem.</p>';
        $jobs=array_reverse($a['jobs']??[],true);$counts=array_count_values(array_column($jobs,'state'));$html.='<p>'.$e(implode(' · ',array_map(fn($k)=>$k.': '.$counts[$k],array_keys($counts)))).'</p>';
        $filter=is_string($query['state']??null)?$query['state']:'';if(!in_array($filter,['queued','running','dead','succeeded','cancelled'],true))$filter='';
        $html.='<p class="actions">';foreach([''=>'All','queued'=>'Queued','running'=>'Running','dead'=>'Dead letter','succeeded'=>'Succeeded','cancelled'=>'Cancelled'] as $state=>$label)$html.='<a href="'.$url.'?state='.$e($state).'">'.$e($label).'</a> ';$html.='</p>';
        if($filter!=='')$jobs=array_filter($jobs,fn($j)=>$j['state']===$filter);$pages=max(1,(int)ceil(count($jobs)/50));$page=is_string($query['page']??null)&&ctype_digit($query['page'])?max(1,min($pages,(int)$query['page'])):1;
        $html.='<div class="table-wrap"><table><thead><tr><th>Job</th><th>Type</th><th>State</th><th>Attempts</th><th>Next attempt (UTC)</th><th>Result</th></tr></thead><tbody>';
        foreach(array_slice($jobs,($page-1)*50,50,true) as $id=>$j){$html.='<tr><td><code>'.$e($id).'</code></td><td>'.$e($j['payload']['event']??'Git sync').'</td><td>'.$e($j['state']).'</td><td>'.$e($j['attempts']).'</td><td>'.$e($j['state']==='queued'?gmdate('c',$j['due']):'—').'</td><td>'.$e($j['error']).($j['kind']==='sync'?'<br>Trigger: '.$e(substr($j['payload']['commit'],0,12)?:'merge').($j['result_commit']!==''?'<br>Applied: '.$e(substr($j['result_commit'],0,12)):''):'');if($j['state']==='dead'&&$c&&$j['config']===$c['id'])$html.='<form method="post" action="'.$url.'">'.$hidden.'<input type="hidden" name="id" value="'.$e($id).'"><button name="action" value="retry">Retry</button></form>';$html.='</td></tr>';}
        $html.='</tbody></table></div><p>Page '.$page.' of '.$pages.' · pending jobs are never evicted.</p><p class="actions">';if($page>1)$html.='<a href="'.$url.'?state='.$e($filter).'&amp;page='.($page-1).'">Previous</a> ';if($page<$pages)$html.='<a href="'.$url.'?state='.$e($filter).'&amp;page='.($page+1).'">Next</a>' ;return $html.'</p>';
    }
}
