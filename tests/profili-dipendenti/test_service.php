<?php
require __DIR__.'/fixture.php';
$root=dirname(__DIR__);if(basename($root)==='tests')$root=dirname($root);
require $root.'/includes/hr_organizzazione.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function apply(OrgPDO $d,int $boss=3,string $start='2026-10-01',?string $end=null,bool $only=false,int $type=1):void{$d->beginTransaction();try{hrOrgAssegnaResponsabile($d,1,$boss,$type,$start,$end,'New note',$only);$d->commit();}catch(Throwable $e){$d->rollBack();throw $e;}}
$d=new OrgPDO();$d->relations=[1=>orgRow(1)];apply($d);check($d->relations[1]['data_fine']==='2026-09-30','history closed before new date');check($d->relations[1]['note']==='Original note','notes preserved');check(count($d->relations)===2,'new row');check(!array_filter($d->writes,fn($s)=>str_starts_with($s,'DELETE')),'no deletes');
$before=$d->relations;apply($d);check($d->relations===$before,'repeat submit is idempotent');
$d=new OrgPDO();$d->relations=[1=>orgRow(1,2,'2026-01-01','2026-10-10'),2=>orgRow(2,3,'2026-10-11')];$before=$d->relations;apply($d,2,'2026-10-01',null,true);check($d->relations===$before&&count($d->writes)===0,'unchanged keeps finite and planned periods');
$d=new OrgPDO();$d->relations=[1=>orgRow(1,2,'2026-10-01')];apply($d);check($d->relations[1]['attiva']===0&&$d->relations[1]['data_fine']===null,'same day keeps cancelled row without negative period');
$d=new OrgPDO();$d->relations=[1=>orgRow(1),2=>orgRow(2,3,'2026-10-15')];$before=$d->relations;try{apply($d);throw new LogicException('Conflict accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'pianificata'),'planned conflict message');}check($d->relations===$before,'planned conflict unchanged');
$d=new OrgPDO();$d->relations=[1=>orgRow(1),2=>orgRow(2,3,'2026-10-15')];apply($d,3,'2026-10-05','2026-10-10');check($d->relations[1]['data_fine']==='2026-10-04'&&$d->relations[2]['data_inizio']==='2026-10-15','future preserves present until date and later plan');
check($d->relations[100]['id_utente_collegato']===2 && $d->relations[100]['data_inizio']==='2026-10-11' && $d->relations[100]['data_fine']==='2026-10-14','finite replacement restores original before later plan');
$d=new OrgPDO();$d->relations=[1=>orgRow(1)];apply($d,3,'2026-10-01','2026-10-05');check($d->relations[100]['data_inizio']==='2026-10-06' && $d->relations[100]['data_fine']===null && $d->relations[100]['note']==='Original note','finite replacement keeps existing future responsibility');
$d=new OrgPDO();$d->relations=[1=>orgRow(1),2=>orgRow(2,3,'2026-09-01',null,1,2)];apply($d,2,'2026-10-01',null,true);check($d->relations[1]['data_fine']==='2026-09-30'&&$d->relations[2]['data_fine']==='2026-09-30','two types normalized');
$d=new OrgPDO();$d->relations=[1=>orgRow(1)];apply($d,0);check(count($d->relations)===1&&$d->relations[1]['data_fine']==='2026-09-30','remove does not delete history');
$d=new OrgPDO();$d->relations=[1=>orgRow(1)];$before=$d->relations;$d->failInsert=true;try{apply($d);throw new LogicException('Failure accepted');}catch(PDOException $e){}check($d->relations===$before&&!$d->tx,'rollback restores closure');
foreach([[1,'2026-10-01',null,1],[4,'2026-10-01',null,1],[3,'2026-02-30',null,1],[3,'2026-10-02','2026-10-01',1],[3,'2026-10-01',null,3]]as $case){$d=new OrgPDO();try{apply($d,$case[0],$case[1],$case[2],false,$case[3]);throw new LogicException('Invalid input accepted');}catch(RuntimeException $e){}check(!$d->writes&&!$d->tx,'invalid input no writes');}
$d=new OrgPDO();try{hrOrgAssegnaResponsabile($d,1,2,1,'2026-10-01',null);throw new RuntimeException('No transaction accepted');}catch(LogicException $e){}
$d=new OrgPDO();$d->relations=[1=>orgRow(1,2,'2026-10-15')];$d->beginTransaction();hrOrgChiudiRelazione($d,1);$d->commit();check($d->relations[1]['attiva']===0&&$d->relations[1]['data_fine']===null,'cancel future no invalid date');
$_SESSION=[];$_POST=[];$token=hrOrgCsrfToken();check(strlen($token)===64,'token');try{hrOrgVerificaCsrf();throw new LogicException('CSRF accepted');}catch(RuntimeException $e){}$_POST=['csrf_token'=>$token];hrOrgVerificaCsrf();
echo "Service: history, same day, planned and finite periods, removal, normalization, rollback, validation, locking and CSRF PASS\n";
