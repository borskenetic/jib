<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    private function allowedRoles(): array
    {
        return array_keys(User::roleOptions());
    }

    private function userValidationRules(?User $user = null): array
    {
        return [
            'fname' => 'required|string|max:255',
            'lname' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'ends_with:@jib.edu.ph',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'role' => ['required', Rule::in($this->allowedRoles())],
            'campus' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'course' => 'nullable|string|max:255|required_if:role,student',
            'password' => $user ? 'nullable|string|min:6' : 'required|string|min:6',
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'users-'.now()->format('Y-m-d').'.csv';
        $roles = User::roleOptions();
        $users = $this->filteredUsersQuery($request)->get();

        return response()->streamDownload(function () use ($users, $roles) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Last name', 'First name', 'Email', 'Role', 'Campus', 'Department', 'Course']);

            foreach ($users as $user) {
                fputcsv($out, [
                    $user->lname,
                    $user->fname,
                    $user->email,
                    $roles[$user->role] ?? ucfirst((string) $user->role),
                    $user->campus,
                    $user->department,
                    $user->course,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function downloadImportTemplate(): StreamedResponse
    {
        $filename = 'user_accounts_import_template.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $columns = ['lname', 'fname', 'email', 'password', 'role', 'campus', 'department', 'course'];

        return response()->streamDownload(function () use ($columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            fclose($out);
        }, $filename, $headers);
    }

    public function create()
    {
        return view('view_accounts.create', [
            'roles' => User::roleOptions(),
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt'
        ]);

        $parsed = $this->parseUserImportCsv($request->file('file')->getRealPath());
        if ($parsed['error']) {
            return redirect()->route('users.index')->with('error', $parsed['error']);
        }

        $result = $this->insertImportRows($this->flagExistingEmails($parsed['rows']));

        return redirect()->route('users.index')->with($this->importFlash($result));
    }

    public function importPreview(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt'
        ]);

        $parsed = $this->parseUserImportCsv($request->file('file')->getRealPath());
        if ($parsed['error']) {
            return redirect()->route('users.index')->with('error', $parsed['error']);
        }

        $data = $this->flagExistingEmails($parsed['rows']);
        $this->storeImportRows($data);

        return view('view_accounts.import_preview', [
            'data' => $data,
            'readyCount' => count(array_filter($data, fn ($row) => empty($row['errors']))),
            'skippedCount' => count(array_filter($data, fn ($row) => ! empty($row['errors']))),
        ]);
    }

    public function importConfirm(Request $request)
    {
        $data = $this->pullStoredImportRows();
        if ($data === null) {
            return redirect()->route('users.index')->with('error', 'That import expired. Upload the file again.');
        }

        $result = $this->insertImportRows($this->flagExistingEmails($data));

        return redirect()->route('users.index')->with($this->importFlash($result));
    }

    private function importFlash(array $result): array
    {
        $inserted = $result['inserted'];
        $skipped = count($result['errors']);
        $message = $inserted === 1
            ? '1 user imported successfully.'
            : "$inserted users imported successfully.";

        if ($skipped > 0) {
            $message .= $skipped === 1
                ? ' 1 row was skipped.'
                : " $skipped rows were skipped.";
        }

        $flash = ['success' => $message];
        if ($skipped > 0) {
            $flash['import_errors'] = $result['errors'];
        }

        return $flash;
    }

    /**
     * Read a user CSV by header name. Course and department may be blank.
     * Columns are matched by header, so a file without a department column
     * still keeps the course value in the course field.
     *
     * @return array{error: ?string, rows: array<int, array<string, mixed>>}
     */
    private function parseUserImportCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['error' => 'Could not read the uploaded file.', 'rows' => []];
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);

            return ['error' => 'The file is empty.', 'rows' => []];
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $aliases = [
            'lname' => 'lname',
            'last_name' => 'lname',
            'lastname' => 'lname',
            'fname' => 'fname',
            'first_name' => 'fname',
            'firstname' => 'fname',
            'email' => 'email',
            'password' => 'password',
            'role' => 'role',
            'campus' => 'campus',
            'department' => 'department',
            'course' => 'course',
        ];

        $map = [];
        foreach ($header as $index => $name) {
            $key = strtolower(trim((string) $name));
            $key = preg_replace('/[\s\-]+/', '_', $key) ?? $key;
            if (isset($aliases[$key]) && ! isset($map[$aliases[$key]])) {
                $map[$aliases[$key]] = $index;
            }
        }

        $missing = array_values(array_diff(
            ['lname', 'fname', 'email', 'password', 'role', 'campus'],
            array_keys($map)
        ));

        if ($missing !== []) {
            fclose($handle);

            return [
                'error' => 'Missing required column: '.implode(', ', $missing).'. Expected headers are lname, fname, email, password, role, and campus. Course and department are optional.',
                'rows' => [],
            ];
        }

        $rows = [];
        $seenEmails = [];
        $line = 1;

        while (($csv = fgetcsv($handle)) !== false) {
            $line++;

            if ($csv === [null] || ! array_filter($csv, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }

            $value = function (string $field) use ($csv, $map): string {
                if (! isset($map[$field])) {
                    return '';
                }

                return trim((string) ($csv[$map[$field]] ?? ''));
            };

            $lname = $value('lname');
            $fname = $value('fname');
            $email = $value('email');
            $password = $value('password');
            $role = strtolower($value('role'));
            $campus = $value('campus');
            $department = $value('department');
            $course = $value('course');
            $errors = [];

            if ($lname === '' || $fname === '' || $email === '' || $password === '' || $role === '' || $campus === '') {
                $errors[] = 'Missing required fields';
            }

            if ($email !== '' && ! str_ends_with(strtolower($email), '@jib.edu.ph')) {
                $errors[] = 'Invalid domain';
            }

            if ($role !== '' && ! in_array($role, $this->allowedRoles(), true)) {
                $errors[] = 'Invalid role';
            }

            $emailKey = strtolower($email);
            if ($emailKey !== '') {
                if (isset($seenEmails[$emailKey])) {
                    $first = $seenEmails[$emailKey];
                    $errors[] = "Duplicate email in this file (row {$first['line']}: {$first['fname']} {$first['lname']})";
                } else {
                    $seenEmails[$emailKey] = [
                        'line' => $line,
                        'fname' => $fname,
                        'lname' => $lname,
                    ];
                }
            }

            $rows[] = [
                'line' => $line,
                'lname' => $lname,
                'fname' => $fname,
                'email' => $email,
                'password' => $password,
                'role' => $role,
                'campus' => $campus,
                'department' => $department,
                'course' => $course,
                'errors' => $errors,
            ];
        }

        fclose($handle);

        return ['error' => null, 'rows' => $rows];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function flagExistingEmails(array $rows): array
    {
        $emails = [];
        foreach ($rows as $row) {
            if (($row['email'] ?? '') !== '') {
                $emails[] = $row['email'];
            }
        }

        if ($emails === []) {
            return $rows;
        }

        $existing = User::query()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->map(fn ($email) => strtolower($email))
            ->flip();

        foreach ($rows as &$row) {
            $emailKey = strtolower((string) ($row['email'] ?? ''));
            if ($emailKey === '' || ! isset($existing[$emailKey])) {
                continue;
            }

            $row['errors'] = array_values($row['errors'] ?? []);
            if (! in_array('Email already exists', $row['errors'], true)) {
                $row['errors'][] = 'Email already exists';
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function storeImportRows(array $rows): void
    {
        $this->deleteStoredImport();

        $id = (string) Str::uuid();
        Storage::disk('local')->put($this->importStoragePath($id), json_encode($rows));
        session(['user_import_id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function pullStoredImportRows(): ?array
    {
        $id = session('user_import_id');
        if (! is_string($id) || ! preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return null;
        }

        $path = $this->importStoragePath($id);
        if (! Storage::disk('local')->exists($path)) {
            session()->forget('user_import_id');

            return null;
        }

        $rows = json_decode(Storage::disk('local')->get($path), true);
        $this->deleteStoredImport();

        return is_array($rows) ? $rows : null;
    }

    private function deleteStoredImport(): void
    {
        $id = session('user_import_id');
        if (is_string($id) && preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            Storage::disk('local')->delete($this->importStoragePath($id));
        }

        session()->forget('user_import_id');
    }

    private function importStoragePath(string $id): string
    {
        return "user-imports/{$id}.json";
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{inserted: int, errors: array<int, string>}
     */
    private function insertImportRows(array $rows): array
    {
        @set_time_limit(0);

        $inserted = 0;
        $errors = [];
        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'] ?? '?';
            $email = (string) ($row['email'] ?? '');
            $rowErrors = array_values(array_filter($row['errors'] ?? []));

            if ($rowErrors !== []) {
                $errors[] = "Row {$line}: ".implode(', ', $rowErrors).($email !== '' ? " ({$email})" : '');
                continue;
            }

            $emailKey = strtolower($email);
            if (isset($seen[$emailKey])) {
                $errors[] = "Row {$line}: Email already exists ({$email})";
                continue;
            }

            try {
                User::create([
                    'lname' => $row['lname'],
                    'fname' => $row['fname'],
                    'email' => $email,
                    'campus' => $row['campus'] ?: null,
                    'department' => ($row['department'] ?? '') !== '' ? $row['department'] : null,
                    'course' => ($row['course'] ?? '') !== '' ? $row['course'] : null,
                    'password' => Hash::make($row['password']),
                    'role' => $row['role'],
                ]);

                $seen[$emailKey] = true;
                $inserted++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$line}: Failed to insert ({$email}).";
            }
        }

        return [
            'inserted' => $inserted,
            'errors' => $errors,
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->userValidationRules());

        User::create([
            'lname' => $validated['lname'],
            'fname' => $validated['fname'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'campus' => $validated['campus'],
            'department' => $validated['department'] ?? null,
            'course' => $validated['role'] === 'student' ? ($validated['course'] ?? null) : null,
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->route('users.index')->with('success', 'User account created successfully.');
    }

    private function filteredUsersQuery(Request $request)
    {
        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('fname', 'like', "%$search%")
                  ->orWhere('lname', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%")
                  ->orWhere('course', 'like', "%$search%")
                  ->orWhere('campus', 'like', "%$search%");
            });
        }

        if ($request->filled('course')) {
            $query->where('course', $request->course);
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        return $query->orderBy('lname')->orderBy('fname');
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 10);
        if (! in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 10;
        }

        $users = $this->filteredUsersQuery($request)->paginate($perPage)->withQueryString();

        $courses = User::whereNotNull('course')->select('course')->distinct()->orderBy('course')->pluck('course');

        return view('view_accounts.list', [
            'users' => $users,
            'courses' => $courses,
            'roles' => User::roleOptions(),
        ]);
    }

    public function edit($id)
    {
        $user = User::findOrFail($id);

        return view('view_accounts.edit', [
            'user' => $user,
            'roles' => User::roleOptions(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $validated = $request->validate($this->userValidationRules($user));

        $payload = [
            'fname' => $validated['fname'],
            'lname' => $validated['lname'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'campus' => $validated['campus'],
            'department' => $validated['department'] ?? null,
            'course' => $validated['role'] === 'student' ? ($validated['course'] ?? null) : null,
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = Hash::make($validated['password']);
        }

        $user->update($payload);

        return redirect()->route('users.index')->with('success', 'User updated successfully!');
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully!');
    }
}
