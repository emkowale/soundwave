<?php
// scan_unused.php — quick unused-file finder for the Soundwave plugin (<=100 lines)
$root = realpath(__DIR__); if(!$root) die("No root\n");
$ignoreDirs = ['.git','vendor','node_modules','.github','assets','languages'];
$all = []; // all PHP files
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
  if ($f->getExtension() !== 'php') continue;
  $rel = str_replace($root.DIRECTORY_SEPARATOR,'',$f->getPathname());
  $skip=false; foreach($ignoreDirs as $d){ if (strpos($rel, $d.DIRECTORY_SEPARATOR)===0 || strpos($rel, DIRECTORY_SEPARATOR.$d.DIRECTORY_SEPARATOR)!==false) { $skip=true; break; } }
  if(!$skip) $all[$f->getPathname()] = 1;
}
function norm($p){ $p=preg_replace('#[/\\\\]+#','/', $p); $parts=[]; foreach(explode('/',$p) as $seg){ if($seg==''||$seg=='.') continue; if($seg=='..') array_pop($parts); else $parts[]=$seg; } return (DIRECTORY_SEPARATOR==='\\' ? implode('\\',$parts) : '/'.implode('/',$parts)); }
function resolve_include($line, $base, $root){
  $m=[];
  // cases: require 'path.php';
  if (preg_match('#(?:require|include)(?:_once)?\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)#i',$line,$m) ||
      preg_match('#(?:require|include)(?:_once)?\s*[\'"]([^\'"]+)[\'"]#i',$line,$m)) {
    $p=$m[1]; return realpath(dirname($base).'/'.$p) ?: $root.$p;
  }
  // cases: __DIR__ . '/path.php'
  if (preg_match('#(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]#i',$line,$m)) {
    return realpath(dirname($base).'/'.$m[1]) ?: dirname($base).'/'.$m[1];
  }
  // cases: SOUNDWAVE_PATH . 'path.php'
  if (preg_match('#(?:require|include)(?:_once)?\s*\(?\s*SOUNDWAVE_PATH\s*\.\s*[\'"]([^\'"]+)[\'"]#i',$line,$m)) {
    return realpath($root.'/'.$m[1]) ?: $root.'/'.$m[1];
  }
  // cases: plugin_dir_path(__FILE__) . 'path.php'
  if (preg_match('#plugin_dir_path\s*\(\s*__FILE__\s*\)\s*\.\s*[\'"]([^\'"]+)[\'"]#i',$line,$m)) {
    return realpath($root.'/'.$m[1]) ?: $root.'/'.$m[1];
  }
  return null;
}
function parse_includes($file, $root){
  $out=[]; $base=$file; $src=@file_get_contents($file); if($src===false) return $out;
  foreach (preg_split("/(\r?\n)/", $src) as $line) {
    $t=resolve_include($line,$base,$root); if($t){ $t=norm($t); $out[$t]=1; }
  }
  return array_keys($out);
}
$graph=[]; foreach(array_keys($all) as $f){ $graph[norm($f)] = array_map('norm', parse_includes($f,$root)); }
$rootFile = norm($root.'/soundwave.php'); if(!isset($graph[$rootFile])){ echo "soundwave.php not found at $root\n"; exit(1); }
$reach=[]; $q=[$rootFile]; while($q){ $cur=array_shift($q); if(isset($reach[$cur])) continue; $reach[$cur]=1;
  foreach(($graph[$cur]??[]) as $n){ if(isset($graph[$n]) && !isset($reach[$n])) $q[]=$n; }
}
$unused=[]; foreach(array_keys($all) as $f){ $nf=norm($f); if(!isset($reach[$nf])) $unused[] = substr($nf, strlen(norm($root.'/'))); }
sort($unused);
echo "== Unused PHP files (not reachable from soundwave.php) ==\n";
if (!$unused) { echo "None ✓\n"; exit(0); }
foreach($unused as $u) echo $u,"\n";
echo "\nTip: if any of these are conditionally loaded (e.g., via actions or different entry points), they may be false positives.\n";
