//This format's the action column
function linkFormat(value) {
  var html = '<a href="?display=nethcti3&view=form&id=' + value + '"><i class="fa fa-edit"></i></a>&nbsp;';
  html += '<a class="delAction" href="?display=nethcti3&action=delete&id=' + value + '"><i class="fa fa-trash"></i></a>';
  return html;
}