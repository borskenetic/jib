<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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

        $file = fopen($request->file('file'), 'r');

        $rowNumber = 0;
        $errors = [];
        $successCount = 0;

        while (($row = fgetcsv($file)) !== FALSE) {
            $rowNumber++;

            if ($rowNumber == 1) continue;

            $lname = trim($row[0] ?? '');
            $fname = trim($row[1] ?? '');
            $email = trim($row[2] ?? '');
            $password = trim($row[3] ?? '');
            $role = strtolower(trim($row[4] ?? ''));
            $campus = trim($row[5] ?? '');
            $department = trim($row[6] ?? '');
            $course = trim($row[7] ?? '');

            if (!$lname || !$fname || !$email || !$password || !$role || !$campus) {
                $errors[] = "Row $rowNumber: Missing fields.";
                continue;
            }

            if (!str_ends_with($email, '@jib.edu.ph')) {
                $errors[] = "Row $rowNumber: Invalid email ($email). Must be @jib.edu.ph";
                continue;
            }

            if (User::where('email', $email)->exists()) {
                $errors[] = "Row $rowNumber: Email already exists ($email)";
                continue;
            }

            if (!in_array($role, $this->allowedRoles(), true)) {
                $errors[] = "Row $rowNumber: Invalid role ($role).";
                continue;
            }

            if ($role === 'student' && $course === '') {
                $errors[] = "Row $rowNumber: Course is required for students.";
                continue;
            }

            try {
                User::create([
                    'lname' => $lname,
                    'fname' => $fname,
                    'email' => $email,
                    'password' => Hash::make($password, ['rounds' => 12]),
                    'role' => $role,
                    'campus' => $campus,
                    'department' => $department ?: null,
                    'course' => $role === 'student' ? $course : null,
                ]);

                $successCount++;

            } catch (\Exception $e) {
                $errors[] = "Row $rowNumber: Failed to insert.";
            }
        }

        fclose($file);

        return redirect()->route('users.index')->with([
            'success' => "$successCount users imported successfully.",
            'import_errors' => $errors
        ]);
    }

    public function importPreview(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt'
        ]);

        $file = fopen($request->file('file'), 'r');

        $data = [];
        $rowNumber = 0;

        while (($row = fgetcsv($file)) !== FALSE) {
            $rowNumber++;

            if ($rowNumber == 1) continue;

            if (empty(array_filter($row))) {
                continue;
            }

            $lname = trim($row[0] ?? '');
            $fname = trim($row[1] ?? '');
            $email = trim($row[2] ?? '');
            $password = trim($row[3] ?? '');
            $role = strtolower(trim($row[4] ?? ''));
            $campus = trim($row[5] ?? '');
            $department = trim($row[6] ?? '');
            $course = trim($row[7] ?? '');

            $errors = [];

            if (!$lname || !$fname || !$email || !$password || !$role || !$campus) {
                $errors[] = 'Missing required fields';
            }

            if ($email && !str_ends_with($email, '@jib.edu.ph')) {
                $errors[] = 'Invalid domain';
            }

            if ($email && User::where('email', $email)->exists()) {
                $errors[] = 'Duplicate email';
            }

            if ($role && !in_array($role, $this->allowedRoles(), true)) {
                $errors[] = 'Invalid role';
            }

            if ($role === 'student' && !$course) {
                $errors[] = 'Course required for students';
            }

            $data[] = [
                'lname' => $lname,
                'fname' => $fname,
                'email' => $email,
                'password' => $password,
                'role' => $role,
                'campus' => $campus,
                'department' => $department,
                'course' => $course,
                'errors' => $errors
            ];
        }

        fclose($file);

        return view('view_accounts.import_preview', compact('data'));
    }

    public function importConfirm(Request $request)
    {
        $data = $request->input('data', []);

        $successCount = 0;

        foreach ($data as $row) {
            $rowErrors = $row['errors'] ?? [];
            if (is_string($rowErrors)) {
                $rowErrors = $rowErrors === '' ? [] : explode('|', $rowErrors);
            }
            if (!empty($rowErrors)) {
                continue;
            }

            User::create([
                'lname' => $row['lname'],
                'fname' => $row['fname'],
                'email' => $row['email'],
                'campus' => $row['campus'] ?? null,
                'department' => $row['department'] ?? null,
                'course' => $row['role'] === 'student' ? ($row['course'] ?? null) : null,
                'password' => Hash::make($row['password'], ['rounds' => 12]),
                'role' => $row['role']
            ]);

            $successCount++;
        }

        return redirect()->route('users.index')
            ->with('success', "$successCount users imported successfully.");
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
