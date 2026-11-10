<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingTruck;
use App\Models\Document;
use Illuminate\Http\Request;

class BookingTruckDocumentController extends Controller
{
    public function index(BookingTruck $bookingTruck)
    {
        return $bookingTruck->documents;
    }

    public function store(Request $request, BookingTruck $bookingTruck)
    {
        $validated = $request->validate([
            'document_type' => 'required|string',
            'attachment' => 'required|file|max:5120',
        ]);

        $path = $request->file('attachment')->store('documents', 'public');

        $document = $bookingTruck->documents()->updateOrCreate(
            ['document_type' => $validated['document_type']],
            ['attachment_path' => $path]
        );

        if ($validated['document_type'] === 'POD') {
            // A POD arrived: clear the overdue alert state (contract 2.2 D-7).
            $bookingTruck->update(['pod_overdue_notified_at' => null]);
        }

        return response()->json($document, 201);
    }

    /**
     * Remove a mistakenly uploaded document, e.g. a wrong POD (contract 2.2 D-5). The record is
     * soft-deleted with who removed it; the leg goes back to "POD pending".
     */
    public function destroy(Request $request, BookingTruck $bookingTruck, Document $document)
    {
        abort_unless(
            $document->documentable_type === BookingTruck::class && (int) $document->documentable_id === $bookingTruck->id,
            404
        );

        $document->update(['deleted_by' => $request->user()->id]);
        $document->delete();

        if ($document->document_type === 'POD') {
            $bookingTruck->update(['pod_overdue_notified_at' => null]);
        }

        return response()->json(null, 204);
    }
}
