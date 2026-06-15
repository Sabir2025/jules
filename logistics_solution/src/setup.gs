/**
 * Initial setup for Google Sheets
 * Run this function once to create necessary sheets and headers
 */
function setupSheets() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();

  // Setup Cargo sheet
  var cargoSheet = ss.getSheetByName('Cargo') || ss.insertSheet('Cargo');
  var cargoHeaders = [
    'ID заказа',
    'Маршрут',
    'Груз',
    'Вес/Объем',
    'Ставка в тенге',
    'Статус',
    'Telegram ID сообщения',
    'Кто забрал',
    'Дата и время бронирования',
    'Логист Phone',
    'Дата создания'
  ];
  cargoSheet.getRange(1, 1, 1, cargoHeaders.length).setValues([cargoHeaders]).setFontWeight('bold');

  // Setup Users sheet
  var usersSheet = ss.getSheetByName('Users') || ss.insertSheet('Users');
  var userHeaders = ['Phone', 'Name', 'Password'];
  usersSheet.getRange(1, 1, 1, userHeaders.length).setValues([userHeaders]).setFontWeight('bold');

  // Protect headers
  cargoSheet.setFrozenRows(1);
  usersSheet.setFrozenRows(1);
}
