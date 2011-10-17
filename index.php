<?php
// ownauth.php should provide global $username and define constants API_USERNAME and API_KEY
require_once 'ownauth.php';

date_default_timezone_set('Europe/Helsinki');
define(API_ENDPOINT, 'http://api3.codebasehq.com');
define(AUTHOR_PREFIX, '###comment by ');// should look the same in regexp
define(AUTHOR_POSTFIX, ":\n");
$knownusers = array('17961' => 'Albert', '18765' => 'Valtter');

// In order to retrieve all user ids, uncomment following:
//askCodebasehq('/main/assignments', true);
// To get priorities uncomment following
//askCodebasehq('/main/tickets/priorities', true);

function askCodebasehq($query, $debug = false) {
    $c = curl_init();
    curl_setopt($c, CURLOPT_URL, API_ENDPOINT . $query);
    if ($debug) {
        header('Content-type: text/plain');
        curl_setopt($c, CURLOPT_HEADER, true);
    } else {
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    }
    curl_setopt($c, CURLOPT_HTTPGET, true);
    curl_setopt($c, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($c, CURLOPT_USERPWD, API_USERNAME . ':' . API_KEY);
    curl_setopt($c, CURLOPT_HTTPHEADER, array(
        'Content-type: application/xml',
        'Accept: application/xml'
    ));

    $r = curl_exec($c);
    curl_close($c);

    if ($debug) {
        exit;
    }
    return $r;
}

function postCodebasehq($xml, $url, $debug = false) {
    $c = curl_init();
    curl_setopt($c, CURLOPT_URL, API_ENDPOINT . $url);
    if ($debug) {
        header('Content-type: text/plain');
        curl_setopt($c, CURLOPT_HEADER, true);
    } else {
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    }
    curl_setopt($c, CURLOPT_POST, true);
    curl_setopt($c, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($c, CURLOPT_USERPWD, API_USERNAME . ':' . API_KEY);
    curl_setopt($c, CURLOPT_HTTPHEADER, array(
        'Content-type: application/xml',
        'Accept: application/xml'
    ));
    curl_setopt($c, CURLOPT_POSTFIELDS, $xml);

    $r = curl_exec($c);
    curl_close($c);

    if ($debug) {
        print_r(array($xml, $url));
        exit;
    }
    return $r;
}

function getTickets() {
    $r = askCodebasehq('/main/tickets?query=status:open');
    $tickets_xml = new SimpleXMLElement($r);
    $tickets = array();

    foreach($tickets_xml->ticket as $ticket) {
        $id = (Int)$ticket->{'ticket-id'};
        $summary = (String)$ticket->summary;
        $reporter = (String)$ticket->reporter;
        $type = (String)$ticket->{'ticket-type'};
        $category = (String)$ticket->category->name;
        $priority = (String)$ticket->priority->name;
        $status = (String)$ticket->status->name;
        //$updated = (String)$ticket->{'updated-at'}; // I.e. 2011-10-04T00:15:12+01:00
        $deadline = (String)$ticket->deadline;
        $estimate = round($ticket->{'estimated-time'}/60, 1); // In hours

        $tickets[] = compact('id', 'summary', 'type', 'reporter', 'category', 'priority', 'status', 'updated', 'deadline', 'estimate');
    }
    return $tickets;
}

function getTable($arr, $id) {
    $r = "<table id=\"$id\" class=\"zebra-striped\"><thead>";
    $r .= "<tr>";
    foreach($arr[0] as $key2=>$row2) {
        $r .= "<th class=\"blue\">" . $key2 . "</th>";
    }
    $r .= "</tr>";
    $r .= '</thead><tbody>';
    foreach($arr as $key=>$row) {
        $r .= "<tr>";
        foreach($row as $key2=>$row2){
            $r .= "<td>" . $row2 . "</td>";
        }
        $r .= "</tr>";
    }
    $r .= "</table>";
    return $r;
}

function showTicketList() {
    global $tickets;
    $tickets = getTable(getTickets(), 'tickets');
}

function getTicketInfo($ticketdata) {
    if (is_numeric($ticketdata)) {
        $r = askCodebasehq('/main/tickets/' . $ticketdata . '/notes', false);
        $ticket = (object) null;
    } else {
        $ticket = json_decode(stripslashes($ticketdata));
        $r = askCodebasehq('/main/tickets/' . $ticket->id . '/notes', true);
    }

    $notes_xml = new SimpleXMLElement($r);
    $notes = array();

    if (sizeof($notes_xml->{'ticket-note'}) > 0) foreach($notes_xml->{'ticket-note'} as $note) {
        $content = (String)$note->content;
        $timestamp = (String)$note->{'updated-at'};
        if (preg_match('/' . AUTHOR_PREFIX . '([a-z0-9<>_.]+)' . AUTHOR_POSTFIX . '(.*)$/is', $content, $m)) {
            $author = $m[1];
            $content = $m[2];
        } else {
            $userid = (String)$note->{'user-id'};
            global $knownusers;
            if (isset($knownusers[$userid])) {
                $author = $knownusers[$userid];
            } else {
                $author = $userid;
            }
        }
        $content = preg_replace('/\*\*(.+?)\*\*/s', '<strong>\\1</strong>', $content);
        if (!empty($content)) {
            $notes[] = compact('content', 'timestamp', 'author');
        }
    }

    $ticket->notes = $notes;
    return $ticket;
}

function saveComment($id, $comment) {
    global $username;
    $xml = '<ticket-note><content>' . htmlspecialchars((AUTHOR_PREFIX . $username . AUTHOR_POSTFIX . $comment)) . '</content></ticket-note>';
    $url = '/main/tickets/' . $id . '/notes';
    return postCodebasehq($xml, $url);
}

function addTicket($summary, $content, $priority, $type = 'feature') {
    global $username;
    $xml = '<ticket><summary>' . htmlspecialchars($summary) . "</summary><ticket-type>$type</ticket-type><priority-id>$priority</priority-id></ticket>";
    $url = '/main/tickets';
    $r = postCodebasehq($xml, $url);
    if ($r) {
        $x = new SimpleXMLElement($r);
        $id = (String)$x->{'ticket-id'};
        return saveComment($id, $content);
    }
    return false;
}

function ajaxReturnNotes() {
    if (is_numeric($_GET['getnotes'])) {
        $ticket = getTicketInfo($_GET['getnotes']);
        header('Content-type: application/json');
        echo(json_encode($ticket->notes));
    }
    exit;
}

function ajaxSaveComment() {
    $id = $_GET['ticket'];
    $comment = stripslashes($_POST['comment']);
    if (!empty($comment) && is_numeric($id) && saveComment($id, $comment)) {
        header('Content-type: application/json');
        echo('{"success": true}');
    } else {
        header('HTTP/1.1 500 Internal Server Error');
    }
    exit;
}

function showTicketPage() {
    $ticket = getTicketInfo($_GET['ticketdata']);
    require_once 'ticketpage.php';
    exit;
}

function handleNewTicket() {
    $fields = array('summary', 'who', 'what', 'why', 'priority');
    extract(cleanUpPost($fields));
    $content = "As a **$who** I want to **$what** in order to **$why**";
    if (!addTicket($summary, $content, $priority, 'feature')) {
        // TODO: Create error handling for this whole script
        print_r(array($summary, $content, $priority));exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF']);
    }
    exit;
}

function cleanUpPost($keys) {
    $result = array();
    foreach($keys as $a) {
        if (empty($_POST[$a])) {
            return false;
        }
        $result[$a] = stripslashes($_POST[$a]);
    }
    return $result;
}

function fileBugReport() {
    $fields = array('browser', 'summary', 'content', 'priority');
    $vars = cleanUpPost($fields);
    extract($vars);
    $os = $_SERVER['HTTP_USER_AGENT'];
    $content = "$content\n\n**Browser**: $browser\n**OS:$os";
    if (!addTicket($summary, $content, $priority, 'bug')) {
        // TODO: Create error handling for this whole script
        print_r(array($summary, $content, $priority));exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF']);
    }
    exit;
}

if (isset($_GET['getnotes'])) {
    ajaxReturnNotes();
} else if (isset($_GET['ticketdata']) && !empty($_GET['ticketdata'])) {
    showTicketPage();
} else if (isset($_POST['comment']) && isset($_GET['ticket'])) {
    ajaxSaveComment();
} else if (isset($_POST['bug'])) {
    fileBugReport();
} else if (isset($_POST['story'])) {
    handleNewTicket();
}
showTicketList();
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
  <head>
    <meta charset="utf-8" />
    <title>Ticket explorer</title>
    <script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/1.6.4/jquery.min.js"></script>
    <link rel="stylesheet" href="bootstrap.min.css">
    <script type="text/javascript">

    /*function openTicketPage(context) {
        var $this = $(context);
        var tds = $this.closest('tr').find('td');
        var tr1 = $this.closest('table').find('tr:first');
        var ticketdata = {};
        tr1.find('th').each(function(i, el) {
            ticketdata[el.innerHTML] = tds[i].innerHTML;
        });

        window.location.href = '?ticket=' + ticketdata.id + '&ticketdata=' + encodeURIComponent(JSON.stringify(ticketdata));
    }*/

    function generateComment(comment, author, time) {
        $quote = $('<blockquote/>');
        $('<p/>')
            .append(comment)
            .appendTo($quote);
        $('<small/>')
            .append(author + ', <em>' + time + '</em>')
            .appendTo($quote);
        return $quote;
    }

    $(function() {
        $("#tickets").tablesorter({ sortList: [[5,0]] });

        $("#newstory form").validate({
            rules: {
                summary: {
                    required: true,
                    minlength: 5
                },
                who: {
                    required: true,
                    minlength: 3
                },
                what: {
                    required: true,
                    minlength: 3
                },
                why: {
                    required: true,
                    minlength: 3
                }
            },
            errorElement: "span",
            errorPlacement: function(error, element) {
                error.appendTo( element.parent().find('.help-block') );
            }
        });
        
        $("#newbug form").validate({
            rules: {
                summary: {
                    required: true,
                    minlength: 5
                },
                content: {
                    required: true,
                    minlength: 5
                },
                browser: {
                    required: true,
                    minlength: 3
                }
            },
            errorElement: "span",
            errorPlacement: function(error, element) {
                error.appendTo( element.parent().find('.help-block') );
            }
        });
        
        $.each([$('#newstory form textarea'), $('#addcomment textarea'), $('#newbug form textarea')], function(i,v) {
            v.jqEasyCounter({
                'maxChars': 200,
                'maxCharsWarning': 150,
                'msgText': '',
                'msgAppendMethod': 'insertAfter',
                'msgFontColor': 'lightgray',
                'msgWarningColor': 'pink'
            });
        });

        /*$("#tickets td").click(function() {
            openTicketPage(this);
        });*/

        var currentId, mouseoverdiv = {}, mouseoverrow = {}, clickfreeze = false;

        function hideComments(id) {
            if (!mouseoverrow[id] && !mouseoverdiv[id]) {
                $("#comments-"+id).hide();
                $commentsection.hide();
            }
        }

        var $commentsection = $("#commentsection"),
            $commentcontainer = $("#comments", $commentsection);

        $commentsection.draggable({ cancel: "blockquote p, blockquote table, #addcomment"});

        $("#tickets tbody tr").hover(function(e) {
            var $this = $(this);
            var id = $this.find('td:first').html();
            if (id != currentId) {
                if (!clickfreeze) {
                    hideComments(currentId);
                }
                mouseoverdiv[currentId] = false;
                mouseoverrow[currentId] = false;
            }
            mouseoverrow[id] = true;
            if (!clickfreeze) {
                currentId = id;
                $commentsection.show();
                $commentsection.hover(function() {
                    mouseoverdiv[id] = true;
                }, function() {
                    mouseoverdiv[id] = false;
                    if (!clickfreeze) {
                        hideComments(currentId);
                    }
                }).click(function() {
                    $('body').click(function(event) {
                        if (!$(event.target).closest('#commentsection').length) {
                            clickfreeze = false;
                            mouseoverdiv[id] = false;
                            hideComments(currentId);
                        };
                    });
                    clickfreeze = true;
                });
                var $comments = $('#comments-'+id);
                if ($comments.length > 0) {
                    if ($comments.css('display') == 'none') {
                        $commentsection.css('left', e.pageX + 'px').css('top', e.pageY + 'px');
                        $comments.show();
                    }
                } else {
                    $commentsection.css('left', e.pageX + 'px').css('top', e.pageY + 'px');
                    $.getJSON('?getnotes=' + id, function(data) {
                        $comments = $('<div class="commentblock" id="comments-'+id+'"/>').appendTo($commentcontainer);
                        var note;
                        var i = data.length;
                        if (i > 0) {
                            while (i--) {
                                note = data[i];

                                $comments.append(generateComment(note.content, note.author, note.timestamp));
                            }
                            if (currentId != id) {
                                $comments.hide();
                            }
                        } else {
                            // if no comments
                        }
                    });
                }
            }
        }, function() {
            if (!clickfreeze) {
                mouseoverrow[currentId] = false;
                setTimeout(function() { hideComments(currentId) }, 100);
            }
        });

        $comment = $("#addcomment textarea", $commentsection);
        $commentbutton = $('#addcomment-button');
        $commentbutton.click(function() {
            var id = currentId;
            var comment = $comment.val();
            $comment.attr('disabled', 'disabled').addClass('commentsending');
            $commentbutton.attr('disabled', 'disabled');
            $.post('?ticket='+id, { 'comment': comment }, function(data) {
                $("#comments-"+id, $commentsection).prepend(generateComment(comment, "Me", "Now"));
                $comment.val('');
                $comment.removeAttr('disabled').removeClass('commentsending');
                $commentbutton.removeAttr('disabled');
            }, "json");
        });
        
        $('.modal-close').click(function(e) {
            $(this).closest('.modal').first().modal('hide');
            e.preventDefault();
        });
    });
    </script>
    <style>
    html {
        height: 100%;
    }
    body {
        overflow: hidden;
    }

    div.container {
        width: auto;
        margin: 20px;
        display: block;
    }
    .help-block {
        color: #CC4442;
    }
    
    #newstory .jqEasyCounterMsg, #newbug .jqEasyCounterMsg {
        position: relative;
        top: -20px;
    }
    
    #newstory .actions, #newbug .actions {
        text-align: center;
        padding-right: 10px;
        padding-left: 10px;
    }
    
    textarea[name=need] {
        height: 56px;
        resize: none;
    }
    
    #newstory label, #newbug label {
        font-weight: bold;
    }
    
    #newstory textarea, #newbug textarea {
        height: 128px;
        resize: none;
        background-color: transparent;
    }

    #commentsection {
        display: none;
        position: absolute;
        background-color: white;
        padding: 10px;
        border: 1px solid grey;
        z-index: 10;
        max-height: 310px;
        overflow-y: auto;
    }

    #comments {
        background: url('spinner.gif') no-repeat center center;
        width: 100%;
        min-height: 30px;
    }

    .commentblock {
        background-color: white;
        min-height: 30px;
    }

    #comments blockquote p, #comments blockquote small {
        display: table;
    }
    
    #comments blockquote strong {
        white-space: pre-wrap;
    }
    
    .commentsending {
        background: url('spinner.gif') no-repeat center center;
    }

    #addcomment textarea {
        height: 38px;
        width: 358px;
        resize: none;
        background-color: transparent;
    }
    #addcomment .jqEasyCounterMsg {
        display: inline-block;
        width: auto !important;
        position: absolute;
        right: 110px;
        bottom: 14px;
    }
    #addcomment button {
        height: 47px;
        width: 86px;
    }
    #postnewbuttons {
        text-align: center;
    }
    #postnewbuttons button {
        margin: 20px;
    }
    </style>
  </head>
  <body>
  <div id="commentsection" class="span8">
    <div id="comments"></div>
    <div id="addcomment">
        <textarea placeholder="Add your comment here"></textarea>
        <button class="btn primary" id="addcomment-button">Comment</button>
    </div>
  </div>
  <div class="container">
  <h1>Currently open tickets</h1>
<?php echo($tickets); ?>
    <div id="postnewbuttons">
        <button class="primary btn" data-controls-modal="newstory">Propose a new feature</button>
        <button class="info btn" data-controls-modal="newbug">File a bug</button>
    </div>
</div>
<div id="newstory" class="modal hide fade">
    <div class="span9">
      <h3>Tell us a user story</h3>
      <form method="post" action="<?php $_SERVER['PHP_SELF']; ?>">
        <div class="clearfix"><input type="text" name="summary" maxlength="80" class="span9" placeholder="Give ticket explanatory name" /><span class="help-block"></span></div>
        <div id="content">
            <div class="clearfix"><label>As a</label><div class="input"><input type="text" maxlength="40" name="who" placeholder="who?" /><span class="help-block"></span></div></div>
            <div class="clearfix"><label>I want to</label><div class="input"><textarea name="what" placeholder="what?"></textarea><span class="help-block"></span></div></div>
            <div class="clearfix"><label>in order to</label><div class="input"><input type="text" maxlength="60" name="why" placeholder="why?" /><span class="help-block"></span></div></div>
            <div class="clearfix"><label>Priority</label>
                <div class="input"><select name="priority">
                    <option value="860265">Critical</option>
                    <option value="860266">High</option>
                    <option value="860267" selected="selected">Normal</option>
                    <option value="860268">Low</option>
                    </select><span class="help-block"></span>
                </div>
            </div>
        </div>
        <div class="actions"><input type="submit" class="btn primary" name="story" value="Submit" />&nbsp;<a class="btn modal-close" href="#">Close</a></div>
      </form>
    </div>
</div>
<div id="newbug" class="modal hide fade">
    <div class="span9">
      <h3>File a bug report</h3>
      <form method="post" action="<?php $_SERVER['PHP_SELF']; ?>">
        <div class="clearfix"><input type="text" name="summary" maxlength="80" id="summary" class="span9" placeholder="Enter a brief description of the bug" /><span class="help-block"></span></div>
        <div id="content">
            <div class="clearfix"><label>Problem</label><div class="input"><textarea name="content" placeholder="what is not working?"></textarea><span class="help-block"></span></div></div>
            <div class="clearfix"><label>Browser</label><div class="input"><input type="text" maxlength="60" name="browser" placeholder="what's the browser and its version?" /><span class="help-block"></span></div></div>
            <div class="clearfix"><label>Priority</label>
                <div class="input"><select name="priority">
                    <option value="860265">Critical</option>
                    <option value="860266">High</option>
                    <option value="860267" selected="selected">Normal</option>
                    <option value="860268">Low</option>
                    </select><span class="help-block"></span>
                </div>
            </div>
        </div>
        <div class="actions"><input type="submit" class="btn primary" name="bug" value="Submit" />&nbsp;<a class="btn modal-close" href="#">Close</a></div>
      </form>
    </div>
</div>

<script type="text/javascript" src="jquery.tablesorter.min.js"></script>
<script type="text/javascript" src="jquery.validate.min.js"></script>
<script type="text/javascript" src="jquery-ui-1.8.16.dragndrop.min.js"></script>
<script type="text/javascript" src="bootstrap-modal.js"></script>
<script type="text/javascript" src="jquery.jqEasyCharCounter.js"></script>
</body>
</html>
