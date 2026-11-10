<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function show(Document $document)
    {
        return $document;
    }

    /** Stream the uploaded file so signed-in users can view it (e.g. a POD, contract 2.2 D-5). */
    public function file(Document $document)
    {
        abort_unless($document->attachment_path && Storage::disk('public')->exists($document->attachment_path), 404, 'File not found.');

        return Storage::disk('public')->response($document->attachment_path);
    }

    public function update(Request $request, Document $document)
    {
        $validated = $request->validate([
            'document_type' => 'sometimes|required|string',
            'number' => 'nullable|string',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
        ]);

        $document->update($validated);

        return $document;
    }

    public function destroy(Request $request, Document $document)
    {
        $document->update(['deleted_by' => $request->user()->id]);
        $document->delete();

        return response()->json(null, 204);
    }
}