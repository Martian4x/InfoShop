<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contact;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

use Inertia\Inertia;

class ContactController extends Controller
{

    public function getContacts($type, $filters){
        // Fetch data from the Collection model
        $query = Contact::select('id', 'name','phone','email','address', 'balance','created_at','type', 'whatsapp')->where('id','!=','1');
        
        if (!empty($filters['search_query'])) {
            $searchTerm = $filters['search_query'];
            
            $query->where(function (Builder $query) use ($searchTerm) {
                $query->where('name', 'LIKE', '%'.$searchTerm.'%')
                      ->orWhere('phone', 'LIKE', '%'.$searchTerm.'%')
                      ->orWhere('email', 'LIKE', '%'.$searchTerm.'%')
                      ->orWhere('address', 'LIKE', '%'.$searchTerm.'%');
            });
        }
        
        // Apply the scope based on the type
        if ($type === 'customer') {
            $query->customers();
        } elseif ($type === 'vendor') {
            $query->vendors(); // Assuming you have a vendors scope
        }
        $perPage = $filters['per_page'] ?? 100;
        $results = $query->paginate($perPage);
        $results->appends($filters);
        return $results;
    }

    public function index(Request $request, $type)
    {
        $filters = $request->only(['search_query', 'per_page']);

        $contacts = $this->getContacts($type, $filters);

        $stores = Store::select('id', 'name')->get();
        // Render the Inertia view with the collections data
        return Inertia::render('Contact/Contact', [
            'contacts' => $contacts,
            'stores'=>$stores,
            'type' =>$type,
            'pageLabel'=>$type.'s',
        ]);
    }

    public function store(Request $request)
    {
        // Validate and create a new contact in one step
        $contact = Contact::create(array_merge(
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:15',
                'whatsapp' => 'nullable|string|max:15',
                'address' => 'nullable|string|max:255',
                'type' => 'required|string|in:customer,vendor', // Only allow specific types
            ]),
            ['balance' => 0] // Append 'balance' manually with a default value of 0
        ));

        // Return a success response
        return response()->json([
            'status' => 'success',
            'message' => 'Contact created successfully',
            'data' => $contact
        ], 201);
    }

    public function update(Request $request, $id)
    {
        // Find the contact by ID or fail with a 404
        $contact = Contact::findOrFail($id);

        // Validate and update the contact in one step
        $contact->update($request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:15',
            'whatsapp' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:255',
            'type' => 'required|string|in:customer,vendor',
        ]));

        // Return a success response
        return response()->json([
            'status' => 'success',
            'message' => 'Contact updated successfully',
            'data' => $contact
        ], 200);
    }

    public function destroy(Request $request, $id)
    {
        // Find the contact by ID or fail with a 404
        $contact = Contact::findOrFail($id);

        // Check if contact has any sales
        $salesCount = \App\Models\Sale::where('contact_id', $id)->count();
        if ($salesCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete this contact. There are {$salesCount} sales associated with this contact."
            ], 422);
        }

        // Check if contact has any purchases
        $purchasesCount = \App\Models\Purchase::where('contact_id', $id)->count();
        if ($purchasesCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete this contact. There are {$purchasesCount} purchases associated with this contact."
            ], 422);
        }

        // Delete the contact
        $contact->delete();

        // Return a success response
        return response()->json([
            'status' => 'success',
            'message' => 'Contact deleted successfully'
        ], 200);
    }
}
