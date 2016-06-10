<?php

// (C) Victor G.Vislobokov <corochoone@gmail.com>
// UNDER GNU GPL VERSION 2 OR 3 - под лицензией GNU GPL 2 или 3

$break_tags = array(
  array('tag' => '/^ *\*/',       'handler' => 'handlerList'),
  array('tag' => '/^ *#/',        'handler' => 'handlerList'),
  array('tag' => '/^\=/',         'handler' => 'handlerHeaders'),
  array('tag' => '/^----/',       'handler' => 'handlerHr'),
  array('tag' => '/^;/',          'handler' => 'handlerTerms'),
  array('tag' => '/^\|/',         'handler' => 'handlerTable'),
  array('tag' => '/^NOTE:/',      'handler' => 'handlerNote'),
  array('tag' => '/^TIP:/',       'handler' => 'handlerTip'),
  array('tag' => '/^IMPORTANT:/', 'handler' => 'handlerImportant'),
  array('tag' => '/^WARNING:/',   'handler' => 'handlerWarning'),
  array('tag' => '/^{{{$/',       'handler' => 'handlerPreBlock'),
  array('tag' => '/^  /',         'handler' => 'handlerPre'),
  array('tag' => '/^ *$/',        'handler' => 'handlerEmptyLine'),
  array('tag' => '/^$/',          'handler' => 'handlerEmptyLine'),
);

$out = array();

$toc = array();
$toc_counter = 0;

$contents = file_get_contents("markup.txt");
$lines = explode("\n", $contents);

$buf = '';

$lines_counter = count($lines);

$config = array('root_dir' => './');

$cur_line = 0;
while ($cur_line < $lines_counter) {
  // Текущая строка.
  $line = chop($lines[$cur_line]);
  
  // Директивы
  if (preg_match('/^~~.*~~$/', $line) === 1) {
    processDirective($cur_line, $lines);
    $cur_line++;
    continue;
  }

  // Состояние обаботки
  $status = FALSE;

  // Проверяем, нет ли в текущей строке тега, который начинает новое форматирование
  // или параграф
  foreach ($break_tags as $tag_item) {

    if (preg_match($tag_item['tag'], $line) === 1) {

      // Если в буфере что-то есть, то обабатываем буфер
      if (strlen($buf) > 0) {
         $buf = processBuf($buf);
         $out[] = $buf;
         $buf = '';
      }

      $handler = $tag_item['handler'];
      $result = $handler($cur_line, $lines);
      $out = array_merge($out, $result);

      $status = TRUE;
      break;
    }
  }
  
  if ($status === FALSE) {
    if (strlen($buf) > 0) $buf .= " ";
    $buf .= chop($line);
    $cur_line++;
  }
}

$out[] = '</body></html>';

if (isset($config['location'])) {
  header("Location: ".$config['location']);
  exit(0);
}

$headers = array();
$headers[] = '<html>';
$headers[] = '<head>';
$headers[] = '  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';

if (isset($config['refresh'])) {
  $headers[] = '  <meta http-equiv="Refresh" content="'.$config['refresh'].'">';
}

if (isset($config['metatags']) && is_array($config['metatags'])) {
  $tmp = '';
  foreach ($config['metatags'] as $tag) {
    if ($tmp !== '') $tmp .= ',';
    $tmp .= $tag;
  }
  $headers[] = '  <meta name="keywords" content="'.$tmp.'" />';
}

$headers[] = '  <link type="text/css" href="style.css" rel="stylesheet">';

if (isset($config['title'])) {
  $headers[] = '  <title>'.$config['title'].'</title>';
}

$headers[] = '</head>';
$headers[] = '<body>';

if (isset($config['toc'])) {
   $headers[] = '<div class="toc">';
   $toc_counter = 0;
   foreach ($toc as $item) {
     $tmp = '';
     for ($i = 1; $i < $item['level']; $i++) {
       $tmp .= '&nbsp;&nbsp;';
     }
     $headers[] = $tmp . '<a href="#toc_'.$toc_counter++.'">'.$item['header'].'</a><br />';
   }
   $headers[] = '</div>';
}

foreach ($headers as $line) {
  print "$line\n";
}

foreach ($out as $line) {
  print "$line\n";
}

exit(0);

function handlerEmpty(&$cur_line, $lines) {
  $cur_line++;
  return(array());
}

function handlerEmptyLine(&$cur_line, $lines) {
  $cur_line++;
  return(array(''));
}

function handlerHR(&$cur_line, $lines) {
  $cur_line++;
  return(array('<hr />'));
}

function handlerPre(&$cur_line, $lines) {
  $result = array();

  $tags = array(
    array('tag' => '/>/', 'totag' => '&gt;'),
    array('tag' => '/</', 'totag' => '&lt;'),
  );

  $result[] = "<pre>";
  $flag = 1;
  while ($flag === 1) {
    $line = chop($lines[$cur_line]);
    if (strpos($line, '  ') === 0) {
      $line = preg_replace('/^  /', '', $line);
      foreach ($tags as $tag) {
        $line = preg_replace($tag['tag'], $tag['totag'], $line);
        $line = processSlashes($line);
      }

      $result[] = $line;
      $cur_line++;
    } else {
      $flag = 0;
    }
  }
  $result[] = "</pre>";
  return($result);
}

function handlerPreBlock(&$cur_line, $lines) {
  $count = 0;

  $tags = array(
    array('tag' => '/>/', 'totag' => '&gt;'),
    array('tag' => '/</', 'totag' => '&lt;'),
  );

  $result = array();
  $cur_line++;

  $result[] = '<pre>';
  for (;;) {
    $line = chop($lines[$cur_line++]);
    if ($line === "}}}") {
      if ($count === 0) break;
      else $count--;
    } else if ($line === "{{{") {
      $count++;
    }

    foreach ($tags as $tag) {
      $line = preg_replace($tag['tag'], $tag['totag'], $line);
    }
    $result[] = $line;
  }
  $result[] = '</pre>';
  return($result);
}

function handlerHeaders(&$cur_line, $lines) {
  global $break_tags, $config, $toc, $toc_counter;

  $buf = chop($lines[$cur_line]);
  $line = '';
  $anchor = '';
  $count = 0;

  while(strpos($buf, '=') === 0) {
    $count++;
    $buf = substr($buf, 1);
  }

  $obuf = $buf;
  $buf = preg_replace('/=*$/', '', $buf);

  if (strcmp($obuf, $buf) !== 0) {
  } else {
    $buf = getBuffer($cur_line, $lines);
    $buf = preg_replace('/^=*/', '', $buf);
    $buf = preg_replace('/=*$/', '', $buf);
  }

  $buf = trim($buf, ' ');
  $buf = processOneTags($buf);
  $stripped_buf = processTags($buf, array(), TRUE);
  $stripped_buf = processStripAlign($stripped_buf);
  $stripped_buf = trim($stripped_buf, ' ');
  if (isset($config['toc'])) {
     $toc[] = array('level' => $count, 'header' => $stripped_buf);
     $anchor = '<a name="toc_'.$toc_counter++.'"></a>';
  }
  if (!isset($config['title'])) {
     $config['title'] = $stripped_buf;
  }

  $buf = processTags($buf);

  $obuf = $buf;
  $buf = processAlign($buf);

  if (strcmp($buf, $obuf) !== 0) $flag = 1;
  else $flag = 0;

  if ($flag === 1) {
    $buf = "<h".$count." ".$buf."</h".$count.">";
  } else {
    $buf = "<h".$count.">".$buf."</h".$count.">";
  }

  $cur_line++;
  return(array($anchor.$buf));
}

function handlerList(&$cur_line, $lines) {
  global $break_tags;

  $lists_open = array();
  $result = array();

  for (;;) {
    $save_line = $cur_line;
    $buf = getBuffer($cur_line, $lines);
    $cur_line++;
    $buf = trim($buf);

    if (strpos($buf, '*') === FALSE && strpos($buf, '#') === FALSE) {
      $cur_line = $save_line;
      break;
    }

    $count = 0;
    for (;;) {
      $sym = substr($buf, 0, 1);
      $buf = substr($buf, 1);
      if ($sym !== '*' && $sym !== '#') {
        $buf = $sym.$buf;
        break;
      }

      if (!isset($lists_open[$count])) {
        if ($sym == '*') {
          $lists_open[$count] = 'ul';
          $result[] = str_pad("", $count * 2, " ") . "<ul>";
        } else {
          $lists_open[$count] = 'ol';
          $result[] = str_pad("", $count * 2, " ") . "<ol>";
        }
      }
      $count++;
    }

    while (count($lists_open) > $count) {
      $item = array_pop($lists_open);
      $result[] = str_pad("", $count * 2, " ") . "</".$item.">";
    }

    $buf = trim($buf);
 
    $buf = processOneTags($buf);
    $buf = processTags($buf);
    $buf = processSlashes($buf);

    $obuf = $buf;
    $buf = ProcessAlign($buf);
    if (strcmp($buf, $obuf) !== 0) $flag = 1;
    else $flag = 0;

    if ($flag === 1) {
      $result[] = str_pad("", $count * 2, " ") . "<li ".$buf."</li>";
    } else {
      $result[] = str_pad("", $count * 2, " ") . "<li>".$buf."</li>";
    }
  }

  while (($count = count($lists_open)) > 0) {
    $item = array_pop($lists_open);
    $result[] = str_pad("", ($count-1) * 2, " ") . "</".$item.">";
  }

  return($result);
}

function handlerTerms(&$cur_line, $lines) {
  $result = array();
  $result[] = '<dl>';

  for (;;) {
     $save_line = $cur_line;
     $buf = getBuffer($cur_line, $lines);
     $buf = trim($buf);
     if (strpos($buf, ';') !== 0) {
       $cur_line = $save_line;
       break;
     }

     $buf = processOneTags($buf);
     $buf = preg_replace('/^; */', '<dt>', $buf);
     $buf = preg_replace('/ *: */', "</dt>\n<dd>", $buf, 1);
     $buf = trim($buf);
     $buf .= "</dd>";

    $buf = processTags($buf);
    $buf = processSlashes($buf);

    $result[] = $buf;
    $cur_line++;
  }

  $result[] = '</dl>';
  return($result);
}

function commonBlock(&$cur_line, $lines, $img) {

  $buf = getBuffer($cur_line, $lines);
  $buf = preg_replace('/^.[^:]*:/', '', $buf);
  $buf = trim($buf, ' ');

  $buf = processOneTags($buf);
  $buf = processTags($buf);
  $buf = processSlashes($buf);

  $obuf = $buf;
  $buf = processAlign($buf);

  if (strcmp($buf, $obuf) !== 0) $flag = 1;
  else $flag = 0;

  if ($flag === 1) {
    $buf = '<p '.$buf."</p>";
  } else {
    $buf = '<p>'.$buf."</p>";
  }
  $cur_line++;
  return(array('<div class="itemblock"><img src="images/'.$img.'">'.$buf.'</div>'));
}

function handlerNote(&$cur_line, $lines) {
  return(commonBlock($cur_line, $lines, 'note.png'));
}

function handlerTip(&$cur_line, $lines) {
  return(commonBlock($cur_line, $lines, 'tip.png'));
}

function handlerImportant(&$cur_line, $lines) {
  return(commonBlock($cur_line, $lines, 'important.png'));
}

function handlerWarning(&$cur_line, $lines) {
  return(commonBlock($cur_line, $lines, 'warning.png'));
}

function handlerTable(&$cur_line, $lines) {
  $flag = 1;
  $table = array();
  
  while ($flag === 1) {
    $line = chop($lines[$cur_line]);
    if (strpos($line, '|') === 0) {
      $tmprow = array();
      $columns = explode('|', $line);
      $ccount = count($columns);
      for ($i = 1; $i < $ccount - 1; $i++) {
        $tmprow[] = $columns[$i];
      }
      $table[] = $tmprow;
      $cur_line++;
    } else {
      $flag = 0;
    }
  }

  $result = array();
  $result[] = "<table>";
  $result[] = "<tbody>";

  $current_row = 0;
  foreach ($table as $row) {
     $current_column = 0;
     $result[] = '<tr>';
     foreach ($row as $column) {
       if (getCountChars($column, ':') != FALSE || strlen($column) === 0) {
         $current_column++;
         continue;
       }

       if (strpos($column, '=') === 0) {
         $buf = '<th';
         $close = '</th>';
         $column = trim($column, '=');
       } else {
         $buf = '<td';
         $close = '</td>';
       }

       $colspan = getEmptyColumns($current_column+1, $row);
       if ($colspan > 0) {
         $colspan++;
         $buf .= ' colspan="'.$colspan.'"';
       }

       $rowspan = getEmptyRows($current_column, $current_row+1, $table);
       if ($rowspan > 0) {
         $rowspan++;
         $buf .= ' rowspan="'.$rowspan.'"';
       }

       $column = trim($column);
       $column = processOneTags($column);
       $ocolumn = $column;
       $column = processAlign($column);

       if (strcmp($column, $ocolumn) !== 0) $flag = 1;
       else $flag = 0;

       $column = processTags($column);
       $column = processSlashes($column);

       if ($flag === 1) {
         $column = ' '.$column;
       } else {
         $column = '>'.$column;
       }
       $buf .= $column.$close;
       $result[] = '  '.$buf;
       $current_column++;
     }
     $result[] = '</tr>';
     $current_row++;
  }
  $result[] = "</tbody>";
  $result[] = "</table>";
  return($result);
}

function getEmptyColumns($current_column, $row) {

  $empty_count = 0;
  $count = count($row);
  for ($i = $current_column; $i < $count; $i++) {
    if (strlen($row[$i]) > 0) return($empty_count);
    $empty_count++;
  }
  return($empty_count);
}

function getEmptyRows($current_column, $current_row, $table) {

  $empty_count = 0;
  for ($rc = $current_row; $rc < count($table); $rc++) {
    $row = $table[$rc];
    if (getCountChars($row[$current_column], ':') != FALSE) $empty_count++;
    else return($empty_count);
  }
  return($empty_count);
}


function getCountChars($buf, $sym) {
   $len1 = strlen($buf);
   $buf = trim($buf, $sym);
   if (strlen($buf) > 0) return(FALSE);
   return($len1);
}

function processBuf($buf) {
  $buf = trim($buf, ' ');
  $buf = processOneTags($buf);
  $obuf = $buf;
  $buf = processAlign($buf);

  if (strcmp($buf, $obuf) !== 0) $flag = 1;
  else $flag = 0;

  $buf = processTags($buf);
  $buf = processSlashes($buf);

  if ($flag === 1) {
    $buf = '<p '.$buf.'</p>';
  } else {
    $buf = '<p>'.$buf.'</p>';
  }
  return($buf);
}

function processAlign($buf) {
  $replace_begin = array(
    array('begin' => "/^}{:/", 'to' => 'style="text-align:center; margin:2em;">'),
    array('begin' => "/^{}:/", 'to' => 'style="text-align:justify; margin:2em;">'),
    array('begin' => "/^{:/", 'to' => 'style="text-align:left; margin:2em;">'),
    array('begin' => "/^}:/", 'to' => 'style="text-align:right; margin:2em;">'),
    array('begin' => "/^}{/", 'to' => 'style="text-align:center;">'),
    array('begin' => "/^{}/", 'to' => 'style="text-align:justify;">'),
    array('begin' => "/^{/", 'to' => 'style="text-align:left;">'),
    array('begin' => "/^}/", 'to' => 'style="text-align:right;">'),
    array('begin' => "/^:/", 'to' => 'style="margin:2em;">')
  );

  $obuf = $buf;
  foreach ($replace_begin as $begin_item) {
     $buf = preg_replace($begin_item['begin'], $begin_item['to'], $buf);
     if (strcmp($buf, $obuf) !== 0) {
      break;
    }
  }
  return($buf);
}

function processStripAlign($buf) {
  $replace_begin = array(
    array('begin' => "/^}{:/", 'to' => ''),
    array('begin' => "/^{}:/", 'to' => ''),
    array('begin' => "/^{:/", 'to' => ''),
    array('begin' => "/^}:/", 'to' => ''),
    array('begin' => "/^}{/", 'to' => ''),
    array('begin' => "/^{}/", 'to' => ''),
    array('begin' => "/^{/", 'to' => ''),
    array('begin' => "/^}/", 'to' => ''),
    array('begin' => "/^:/", 'to' => '')
  );

  $obuf = $buf;
  foreach ($replace_begin as $begin_item) {
     $buf = preg_replace($begin_item['begin'], $begin_item['to'], $buf);
     if (strcmp($buf, $obuf) !== 0) {
      break;
    }
  }
  return($buf);
}

function processOneTags($buf) {
  $one_tags = array(
    array('tag' => '/\(tm\)/', 'totag' => '&#8482;'),
    array('tag' => '/\(r\)/', 'totag' => '&#174;'),
    array('tag' => '/>/', 'totag' => '&gt;'),
    array('tag' => '/</', 'totag' => '&lt;'),
    array('tag' => "/\"/", 'totag' => '&quot;'),
    array('tag' => '/\(c\)/', 'totag' => '&#169;')
  );

  foreach ($one_tags as $tag_item) {
    $buf = preg_replace($tag_item['tag'], $tag_item['totag'], $buf);
  }

  return($buf);
}

function processSlashes($buf) {
  $pos = 0;
  while(($pos = strpos($buf, '\\', $pos)) !== FALSE) {
    $sub = substr($buf, $pos, 2);
    $begin = substr($buf, 0, $pos);
    if (strcmp($sub, '\\'.'\\') !== 0) {
      $end = substr($buf, $pos+1);
      $buf = $begin.$end;
    } else {
      $sub = substr($buf, $pos, 3);
      if (strcmp($sub, '\\'.'\\'.'\\') === 0) {
        $end = substr($buf, $pos+3);
        $buf = $begin."\\".$end;
        $pos++;
      } else {
        $end = substr($buf, $pos+2);
        $buf = $begin.'<br />'.$end;
        $pos+=6;
      }
    }
  }
  return($buf);
}

function processTags($buf, $disabled_tags = array(), $strip = FALSE) {
  $tags = array(
    array('begin' => '%{', 'end' => '%',  'delimiter' => '}', 'ex' => array(), 'fragments' => array(1), 'callback' => 'processClassID'),
    array('begin' => '??', 'end' => '??', 'delimiter' => '|', 'ex' => array(), 'fragments' => array(), 'callback' => 'processColors'),
    array('begin' => '{{', 'end' => '}}', 'delimiter' => '|', 'ex' => array(), 'fragments' => array(), 'callback' => 'processImages'),
    array('begin' => '[[', 'end' => ']]', 'delimiter' => '|', 'ex' => array(), 'fragments' => array(1), 'callback' => 'processLinks'),
    array('begin' => '@@', 'end' => '@@', 'delimiter' => '|', 'ex' => array(), 'fragments' => array(1), 'callback' => 'processAnchors'),
    array('begin' => '**', 'end' => '**', 'delimiter' => '',  'ex' => array(), 'fragments' => array(), 'callback' => 'processBoldFont'),
    array('begin' => '//', 'end' => '//', 'delimiter' => '',  'ex' => array('ftp://', 'http://', 'https://'), 'fragments' => array(), 'callback' => 'processItalicFont'),
    array('begin' => '^^', 'end' => '^^', 'delimiter' => '',  'ex' => array(), 'fragments' => array(), 'callback' => 'processSuperFont'),
    array('begin' => ',,', 'end' => ',,', 'delimiter' => '',  'ex' => array(), 'fragments' => array(), 'callback' => 'processSubFont'),
    array('begin' => "''", 'end' => "''", 'delimiter' => '',  'ex' => array(), 'fragments' => array(), 'callback' => 'processMonospacedFont'),
    array('begin' => '%%', 'end' => '%%', 'delimiter' => '',  'ex' => array(), 'fragments' => array(), 'callback' => 'processCode'),
    array('begin' => '--', 'end' => '--', 'delimiter' => '',  'ex' => array(), 'fragments' => array(), 'callback' => 'processStrikeFont'),
    array('begin' => '__', 'end' => '__', 'delimiter' => '',  'ex' => array(), 'fragments' => array(), 'callback' => 'processUnderlineFont'),
  );

  foreach ($tags as $tag) {
    if (in_array($tag['begin'], $disabled_tags)) {
      continue;
    }
    $buf = getItemsFromTags($buf, $tag, $strip);
  }
  return($buf);
}


function getItemsFromTags($buf, $taginfo, $strip = FALSE) {

  while (($begin = strpos($buf, $taginfo['begin'])) !== FALSE) {
    $ex = $taginfo['ex'];
    $flag = 0;
    foreach ($ex as $rule) {
       if (strpos($buf, $rule) === $begin + strlen($taginfo['begin']) - strlen($rule)) {
         $flag = 1;
         break;
       }
    }
    if ($flag === 1) break;
    $end = strpos($buf, $taginfo['end'], $begin + 1);
    if ($end === FALSE) {
      break;
    }

    $start = $begin + strlen($taginfo['begin']);
    $subbuf = substr($buf, $start, $end - $start);

    $callback = $taginfo['callback'];
    $subbuf = $callback($subbuf, $taginfo, $strip);

    $buf_begin = substr($buf, 0, $begin);
    $buf_end = substr($buf, $end + strlen($taginfo['end']));
    $buf = $buf_begin . $subbuf . $buf_end;
  }
  return($buf);
}

function processBoldFont($buf, $taginfo, $strip = FALSE) {
  if ($strip) return(processTags($buf, array(), TRUE));
  return("<strong>".processTags($buf)."</strong>");
}

function processItalicFont($buf, $taginfo, $strip = FALSE) {
  if ($strip) return(processTags($buf, array(), TRUE));
  return("<em>".processTags($buf)."</em>");
}

function processSuperFont($buf, $taginfo, $strip = FALSE) {
  if ($strip) return(processTags($buf, array(), TRUE));
  return("<sup>".processTags($buf)."</sup>");
}

function processSubFont($buf, $taginfo, $strip = FALSE) {
  if ($strip) return(processTags($buf, array(), TRUE));
  return("<sub>".processTags($buf)."</sub>");
}

function processMonospacedFont($buf, $taginfo, $strip = FALSE) {
  if ($strip) return(processTags($buf, array(), TRUE));
  return("<tt>".processTags($buf)."</tt>");
}

function processCode($buf, $taginfo, $strip = FALSE) {
  if ($strip) return(processTags($buf, array(), TRUE));
  return("<code>".processTags($buf)."</code>");
}

function processStrikeFont($buf, $taginfo, $strip = FALSE) {
  if ($strip) return(processTags($buf, array(), TRUE));
  return('<span style="text-decoration: line-through;">'.processTags($buf).'</span>');
}

function processUnderlineFont($buf, $taginfo, $strip = FALSE) {
  if ($strip) return(processTags($buf, array(), TRUE));
  return('<span style="text-decoration: underline;">'.processTags($buf).'</span>');
}

function processLinks($buf, $taginfo, $strip = FALSE) {
  $params = explode($taginfo['delimiter'], $buf, 2);

  if (isset($params[0])) {
    $url = $params[0];
  } else {
    $url = "";
  }

  if (isset($params[1])) {
    $text = processTags($params[1], array('[[', '@@'), $strip);
  } else {
    $text = $params[0];
  }

  if ($strip) return($text);

  if (strpos($url, "http://") === 0 || strpos($url, "https://") === 0 || strpos($url, "ftp://") === 0) {
    $out = '<a href="'.$url.'" class="external">'.$text.'</a>';
  } else {
    $out = '<a href="'.$url.'">'.$text.'</a>';
  }
  return($out);
}


function processAnchors($buf, $taginfo, $strip = FALSE) {
  $params = explode($taginfo['delimiter'], $buf, 2);

  if (isset($params[0])) {
    $anchor = $params[0];
  } else {
    $anchor = '';
  }
  if (isset($params[1])) {
    $name = processTags($params[1], array('[[', '@@'), $strip);
  } else {
    $name = '';
  }

  if ($strip) return($name);

  $out = '<a name="'.$anchor.'" id="'.$anchor.'" class="anchor">'.$name.'</a>';
  return($out);
}



function processImages($buf, $taginfo, $strip = FALSE) {

  if ($strip) return('');

  $params = explode($taginfo['delimiter'], $buf, 4);

  if (isset($params[0])) {
    $image = $params[0];
  } else {
    $image = "";
  }

  if (isset($params[1])) {
    $alt = $params[1];
  }

  if (isset($params[2])) {
    $width = $params[2];
  }

  if (isset($params[3])) {
    $height = $params[3];
  }

  $add = "";
  if (isset($alt)) {
    $add = 'alt="'.$alt.'"';
  }
  
  if (isset($width)) {
    if (strlen($add) > 0) $add .= ' ';
    $add = 'width="'.$width.'"';
  }

  if (isset($height)) {
    if (strlen($add) > 0) $add .= ' ';
    $add = 'height="'.$height.'"';
  }

  if (strlen($add) > 0) $add = " ".$add;
  $out = '<img src="'.$image.'"'.$add.'>';
  return($out);
}

function processColors($buf, $taginfo, $strip = FALSE) {

  $params = explode($taginfo['delimiter'], $buf, 3);

  if (isset($params[0]) && strlen($params[0]) > 0) {
    $color = "color: ".$params[0].";";
  } else {
    $color = '';
  }

  if (isset($params[1]) && strlen($params[1]) > 0) {
    $bcolor = "background: ".$params[1].";";
  } else {
    $bcolor = '';
  }

  if (isset($params[2])) {
    $text = processTags($params[2], array('??','%{'), $strip);
  } else {
    $text = '';
  }

  if ($strip) return($text);

  $out = '<span style="'.$color.$bcolor.'">'.$text.'</span>';
  return($out);
}

function processClassID($buf, $taginfo, $strip = FALSE) {

  $flag = 0;
  $params = explode($taginfo['delimiter'], $buf, 2);

  if (isset($params[0]) && strlen($params[0]) > 0) {
     $name = trim($params[0]);
     if (strpos($name, '#') === 0) {
       // Идентификатор, а не класс
       $name = substr($name, 1);
       $flag = 1;
     }
  } else {
    $name = '';
  }

  if (isset($params[1])) {
    $text = processTags($params[1], array(), $strip);
  } else {
    $text = '';
  }

  if ($strip) return($text);

  if (strlen($name) > 0) {
    if ($flag === 0) {
      $out = '<span class="'.$name.'">'.$text.'</span>';
    } else {
      $out = '<span id="'.$name.'">'.$text.'</span>';
    }
  } else {
    $out = $text;
  }
  return($out);
}

function getBuffer(&$cur_line, &$lines) {
  global $break_tags;

    $buf = '';
    $flag = 0;

    $count_lines = count($lines);
    for ($i = $cur_line; $i < $count_lines; $i++) {
      $line = chop($lines[$i]);

      if (preg_match('/^~~.*~~$/', $line) === 1) {
        processDirective($cur_line, $lines);
        $cur_line++;
        continue;
      }

      if ($buf !== "") {
        foreach ($break_tags as $tag_item) {
          if (preg_match($tag_item['tag'], $line) === 1) {
            $cur_line--;
            $flag = 1;
            break;
          }
        }
      }

      if ($flag === 1) {
        break;
      }
      if (strlen($buf) > 0) $buf .= " ";
      $buf .= $line;

      $cur_line++;
    }

  return($buf);
}

function processDirective(&$cur_line, &$lines) {

  $line = trim(chop($lines[$cur_line]), '~');
  $params = explode(':', $line, 2);
  $directive = array_shift($params);

  $directives = array(
    array('directive' => 'REDIRECT', 'handler' => 'dhanlderRedirect'),
    array('directive' => 'REFRESH', 'handler' => 'dhandlerRefresh'),
    array('directive' => 'TOC', 'handler' => 'dhandlerToc'),
    array('directive' => 'TOC_ONELEVEL', 'handler' => 'dhandlerTocOneLevel'),
    array('directive' => 'INCLUDE', 'handler' => 'dhandlerInclude'),
    array('directive' => 'TITLE', 'handler' => 'dhandlerTitle'),
    array('directive' => 'TAGS', 'handler' => 'dhandlerTags'),
    array('directive' => 'METATAGS', 'handler' => 'dhandlerMetaTags'),
    array('directive' => 'NEXT', 'handler' => 'dhandlerNext'),
    array('directive' => 'TOP', 'handler' => 'dhanlderTop'),
    array('directive' => 'TOPNAVPANEL', 'handler' => 'dhandlerTopNavPanel'),
    array('directive' => 'BOTTOMNAVPANEL', 'handler' => 'dhanlderBottomNavPanel'),
  );

  foreach ($directives as $dinfo) {
    if ($directive === $dinfo['directive']) {
      $handlerFunc = $dinfo['handler'];
      $handlerFunc($cur_line, $lines, $params);
    }
  }
}

function dhandlerInclude(&$cur_line, &$lines, $params) {
  global $config;
  
}

function dhandlerTitle(&$cur_line, &$lines, $params) {
  global $config;

  $config['title'] = $params[0];
}

function dhandlerRedirect(&$cur_line, &$lines, $params) {
  global $config;
  
  $config['location'] = $params[0];
}

function dhandlerRefresh(&$cur_line, &$lines, $params) {
  global $config;
  
  $config['refresh'] = $params[0];
}

function dhandlerToc(&$cur_line, &$lines, $params) {
  global $config;

  $config['toc'] = TRUE;
}

function dhandlerTocOneLevel(&$cur_line, &$lines, $params) {
  global $config;

  $config['toc_one_level'] = TRUE;
}

function dhandlerTags(&$cur_line, &$lines, $params) {
  global $config;

  $tags = explode(' ', $params[0]);
  foreach ($tags as $tag) {
    $tag = trim($tag);
    $config['tags'][] = $tag;
  }
}

function dhandlerMetaTags(&$cur_line, &$lines, $params) {
  global $config;

  $tags = explode(',', $params[0]);
  foreach ($tags as $tag) {
    $tag = trim($tag);
    $config['metatags'][] = $tag;
  }
}

function dhandlerNext(&$cur_line, &$lines, $params) {
  global $config;

  $config['next'] = $params[0];
}

function dhandlerTop(&$cur_line, &$lines, $params) {
  global $config;

  $config['top'] = $params[0];
}

function dhandlerTopNavPanel(&$cur_line, &$lines, $params) {
  global $config;

  $config['top_nav_panel'] = $params[0];
}

function dhandlerBottomNavPanel(&$cur_line, &$lines, $params) {
  global $config;

  $config['bottom_nav_panel'] = $params[0];
}

?>
