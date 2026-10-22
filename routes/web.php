<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookLogController;
use App\Http\Controllers\RFIDScanController;
use App\Http\Controllers\BookImportController; 
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EbookController;
use App\Http\Controllers\ProspectusController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\AttendanceLogController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\IdCardController;
use Spatie\SimpleExcel\SimpleExcelWriter;
use App\Http\Controllers\PendingStudentController;
use App\Http\Controllers\PendingEmployeeController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\RoomReservationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SMSController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\FineSettingController;
use App\Http\Controllers\OpenLibraryCopyCatalogController;
use App\Http\Controllers\ZendyController;
use App\Http\Controllers\ZendyReportController;
use App\Http\Controllers\SSOController;
use Carbon\Carbon;
use App\Models\Book;

// =============================
// Public Routes
// =============================
Route::get('/', function () {
    return view('index'); // <-- new home page
})->name('home');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/index', fn() => redirect()->route('attendance_logs.index'));
Route::get('/filter/years', [BookController::class, 'getYears']);
Route::get('/filter/courses', [BookController::class, 'getCourses']);
Route::get('/rooms/book', [RoomReservationController::class, 'create'])->name('rooms.book');
Route::post('/rooms/book', [RoomReservationController::class, 'store'])->name('room-reservations.store');
Route::get('/rooms/schedule', [RoomReservationController::class, 'schedule'])->name('rooms.schedule');
Route::get('/rooms/{id}/show', [RoomReservationController::class, 'show'])->name('rooms.show');

Route::get('/register', [PendingStudentController::class, 'create'])->name('patron.register');
Route::post('/register', [PendingStudentController::class, 'store'])->name('pending.store');

Route::get('/pending/approve/{id}', [PendingStudentController::class, 'approve'])->name('pending.approve');
Route::get('/pending/reject/{id}', [PendingStudentController::class, 'reject'])->name('pending.reject');
// Feedback Form (User-facing)
Route::get('/feedback', [FeedbackController::class, 'create'])->name('feedback.create');
Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');
Route::get('/books/copies', [BookController::class, 'viewCopies'])->name('books.copies');



Route::post('/checkout/process', [CheckoutController::class, 'process'])
    ->name('checkout.process');

Route::get('/opac', [BookController::class, 'landingPage'])->name('landing');

Route::get('/student/qr/{qrcode}', [StudentController::class, 'profile'])
    ->name('student.qr.profile');
Route::get('/kiosk/scan', fn() => view('kiosk.scan'));

Route::post('/students/profile/request', 
    [StudentController::class, 'submitEditRequest']
)->name('students.profile.request');

Route::post('/zendy/store', [ZendyController::class, 'store']);
Route::get('/sso-library', [SSOController::class, 'redirectToLibrary'])
    ->name('sso.library')
    ->middleware(['auth', 'can:canAccessZendy']);

// Zendy area (all allowed users)
Route::middleware(['auth', 'can:canAccessZendy'])->group(function () {
    Route::get('/zendy', [ZendyController::class, 'home'])->name('zendy.home');
    Route::get('/zendy/launch', [ZendyController::class, 'launch'])->name('zendy.launch');
    Route::get('/zendy/go', [ZendyController::class, 'go'])->name('zendy.go');
    Route::get('/zendy/activity', [ZendyController::class, 'activity'])->name('zendy.activity');
    Route::get('/zendy/activity/export', [ZendyController::class, 'exportActivity'])->name('zendy.activity.export');
    Route::post('/zendy/session-end', [ZendyController::class, 'sessionEnd'])->name('zendy.session-end');
});

// =============================
// Admin + Staff (circulation / catalog — not the full admin dashboard)
// =============================
Route::middleware(['auth', 'can:isAdminOrStaff'])->group(function () {
    Route::resource('book', BookController::class);
    
    Route::get('/books', [BookController::class, 'index'])->name('books.index');

    Route::get('/rfid-scanner', [RFIDScanController::class, 'index'])->name('rfid.scanner');
    Route::post('/rfid-scan', [RFIDScanController::class, 'scan'])->name('rfid.scan');

    Route::delete('/books/{book}', [BookController::class, 'destroy'])->name('books.destroy');
    Route::post('/import-books', [BookImportController::class, 'import'])->name('books.import');

    Route::resource('ebooks', EbookController::class);
    Route::get('/program/{program?}/courses', [App\Http\Controllers\EbookController::class, 'getCourses'])->name('program.courses');
    Route::get('/ebooks/get-courses/{programId}', [EbookController::class, 'getCourses']);
    Route::get('/export-books', [ExportController::class, 'exportBooks'])->name('export.books');
    Route::get('/export-transactions', [ExportController::class, 'exportTransactions'])->name('transactions.export');

    // Attendance Scanner and Book Reports (Admin + Staff)
    Route::get('/attendance', [AttendanceController::class, 'showScanner'])->name('attendance.scan');
    Route::post('/attendance', [AttendanceController::class, 'scan'])->name('attendance.process');
    Route::get('/download-book-report', [BookController::class, 'downloadBookReport'])->name('book.report.download');
    Route::get('/book-report-by-course', [BookController::class, 'bookReportByCourse'])->name('book.report.by.course');
    Route::get('/attendance/change-video', [AttendanceController::class, 'showChangeVideo'])->name('attendance.changeVideo');
    Route::post('/attendance/upload-video', [AttendanceController::class, 'uploadVideo'])->name('attendance.uploadVideo');
    
    Route::get('/patron-suggestions', [BookLogController::class, 'patronSuggestions'])->name('patron.suggestions');
    Route::get('/book-suggestions', [BookLogController::class, 'bookSuggestions'])->name('book.suggestions');
    
    Route::get('/catalog/copy/openlibrary', [OpenLibraryCopyCatalogController::class, 'searchForm'])
        ->name('catalog.copy.openlibrary.form');
    
    Route::post('/catalog/copy/openlibrary/search', [OpenLibraryCopyCatalogController::class, 'search'])
        ->name('catalog.copy.openlibrary.search');
    
    Route::post('/catalog/copy/openlibrary/store', [OpenLibraryCopyCatalogController::class, 'store'])
        ->name('catalog.copy.openlibrary.store');
        
    Route::get('/student/pending-requests', 
        [StudentController::class, 'pendingRequests']
    )->name('students.pending.requests');
    
    Route::post('/admin/requests/{id}/approve',
        [StudentController::class, 'approveRequest']
    )->name('admin.requests.approve');
    
    Route::post('/admin/requests/{id}/reject',
        [StudentController::class, 'rejectRequest']
    )->name('admin.requests.reject');
    
    Route::post('/students/import', [StudentController::class, 'import'])->name('students.import');
    Route::get('/students/export', [StudentController::class, 'export'])->name('students.export');

});

// =============================
// Admin-only Routes
// =============================
Route::middleware(['auth', 'can:isAdmin'])->group(function () {
    Route::get('/zendy/logs', [ZendyController::class, 'index'])->name('zendy.logs');
    Route::get('/zendy/logs/export', [ZendyController::class, 'exportLogs'])->name('zendy.logs.export');
    Route::get('/zendy/reports', [ZendyReportController::class, 'index'])->name('zendy.reports');
    Route::get('/zendy/reports/export', [ZendyReportController::class, 'export'])->name('zendy.reports.export');

    Route::get('/feedbacks', [FeedbackController::class, 'index'])->name('feedback.index');

    // Book Logs
    Route::get('/logs', [BookLogController::class, 'index'])->name('logs.index');
    Route::post('/logs', [BookLogController::class, 'store'])->name('logs.store');

    // Prospectus
    Route::prefix('prospectus')->name('prospectus.')->group(function () {
        Route::get('/', [ProspectusController::class, 'index'])->name('index');
        Route::post('/store-program', [ProspectusController::class, 'storeProgram'])->name('storeProgram');
        Route::get('/{program}/years', [ProspectusController::class, 'getProgramYears'])->name('getProgramYears');
    });
    // Course management
    Route::post('/prospectus/{year}/course', [ProspectusController::class, 'storeCourse'])->name('prospectus.storeCourse');
    Route::put('/prospectus/course/{course}', [ProspectusController::class, 'updateCourse'])->name('prospectus.updateCourse');
    Route::delete('/prospectus/course/{course}', [ProspectusController::class, 'destroyCourse'])->name('prospectus.destroyCourse');
    Route::put('/prospectus/program/{program}', [ProspectusController::class, 'updateProgram'])->name('prospectus.updateProgram');
    Route::delete('/prospectus/program/{program}', [ProspectusController::class, 'destroyProgram'])->name('prospectus.destroyProgram');
    // Show form to add subject (course & year are passed via query)
    Route::get('/prospectus/add-subject', [ProspectusController::class, 'createSubject'])->name('prospectus.addSubject');
    // Store new subject
    Route::post('/prospectus/store-subject', [ProspectusController::class, 'storeSubject'])->name('prospectus.storeSubject');

    // Student Management
    Route::get('/students/report', [StudentController::class, 'index'])->name('students.report');
    Route::resource('students', StudentController::class);
    Route::get('/idcard/download/{id}', [IdCardController::class, 'download'])->name('idcard.download');

    // Attendance Logs
    Route::get('/attendance-logs', [AttendanceLogController::class, 'index'])->name('attendance_logs.index');
    Route::get('/attendance-logs/export/excel', [AttendanceLogController::class, 'exportExcel'])->name('attendance_logs.export.excel');
    Route::get('/attendance-logs/export/pdf', [AttendanceLogController::class, 'exportPdf'])->name('attendance_logs.export.pdf');

    // File Repository
    Route::get('/files', [FileController::class, 'index'])->name('files.index');
    Route::post('/files/upload', [FileController::class, 'upload'])->name('files.upload');
    Route::get('/files/view/{id}', [FileController::class, 'view'])->name('files.view');
    Route::get('/files/download/{id}', [FileController::class, 'download'])->name('files.download');
    Route::delete('/files/delete/{id}', [FileController::class, 'delete'])->name('files.delete');

    // User Management
    Route::get('/view-users', [UserController::class, 'index'])->name('users.index');
    Route::get('/view-users/export', [UserController::class, 'export'])->name('users.export');
    Route::get('/view-users/import-template', [UserController::class, 'downloadImportTemplate'])->name('users.import.template');
    Route::get('/edit-user/{id}', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/update-user/{id}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/delete-user/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    
    Route::get('/create-user', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');

    Route::get('/idcard/{id}', [IdCardController::class, 'generate']);
    Route::get('/idcard/front/{id}', [IdCardController::class, 'front']);
    Route::get('/idcard/back/{id}', [IdCardController::class, 'back'])->name('idcard.back');

    Route::get('/admin/pending', [StudentController::class, 'pending'])->name('students.pending');
    Route::post('/admin/pending/{id}/approve', [StudentController::class, 'approve'])->name('students.approve');
    Route::post('/admin/pending/{id}/reject', [StudentController::class, 'reject'])->name('students.reject');
    Route::get('/pending', [PendingStudentController::class, 'index'])->name('pending.index');
    
    Route::get('/pending/employees', [PendingEmployeeController::class, 'index'])->name('pending.employees');
    Route::post('/pending/employees/approve/{id}', [PendingEmployeeController::class, 'approve'])->name('employees.approve');
    Route::post('/pending/employees/reject/{id}', [PendingEmployeeController::class, 'reject'])->name('employees.reject');
    
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('employees.index'); // List faculty
        Route::get('/edit/{id}', [EmployeeController::class, 'edit'])->name('employees.edit'); // Edit faculty
        Route::put('/update/{id}', [EmployeeController::class, 'update'])->name('employees.update'); // Update faculty
        Route::delete('/delete/{id}', [EmployeeController::class, 'destroy'])->name('employees.destroy'); // Delete faculty

    });

    
    Route::get('/rooms/pending', [RoomReservationController::class, 'pending'])->name('rooms.pending');
    Route::post('/rooms/{id}/approve', [RoomReservationController::class, 'approve'])->name('rooms.approve');
    Route::post('/rooms/reject/{id}', [RoomReservationController::class, 'reject'])->name('rooms.reject');
    Route::delete('/resrooms/{id}', [RoomReservationController::class, 'destroy'])->name('resrooms.destroy');
    Route::get('/rooms/check-availability', [RoomReservationController::class, 'checkAvailability'])->name('rooms.check');
    Route::get('/rooms/logs', [RoomReservationController::class, 'logs'])->name('rooms.logs');
    
    Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::get('/rooms/create', [RoomController::class, 'create'])->name('rooms.create');
    Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
    Route::get('/rooms/{id}/edit', [RoomController::class, 'edit'])->name('rooms.edit');
    Route::put('/rooms/{id}', [RoomController::class, 'update'])->name('rooms.update');
    Route::delete('/rooms/{id}', [RoomController::class, 'destroy'])->name('rooms.destroy');
    
    Route::get('/admin/fines', [FineSettingController::class, 'edit'])->name('fines.edit');
    Route::post('/admin/fines', [FineSettingController::class, 'update'])->name('fines.update');
    
    Route::get('/sms-blast', [SmsController::class,'index'])->name('sms.page');
    Route::post('/sms/send', [SmsController::class,'send'])->name('sms.send');
    
    Route::get('/sms/scan-message', [SmsController::class,'scanMessage']);
    Route::post('/sms/scan-message', [SmsController::class,'updateScanMessage']);
    Route::get('/sms/count',[SmsController::class,'count'])->name('sms.count');
    Route::post('/users/import', [UserController::class, 'import'])->name('users.import');
    
    Route::post('/users/import/preview', [UserController::class, 'importPreview'])->name('users.import.preview');
    Route::post('/users/import/confirm', [UserController::class, 'importConfirm'])->name('users.import.confirm');
});
