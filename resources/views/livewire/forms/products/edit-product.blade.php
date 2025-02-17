<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductTag;
use App\Models\ProductVariation;
use App\Models\ProductVariationImage;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

new class extends Component {

    use WithFileUploads, LivewireAlert;

    public $name;
    public $price;
    public $category_id;
    public $description;
    public $selling_tags;
    public $selling_tags_ids = [];
    public $product;
    public $images = [];
    public $discountVisible = false;
    public $discountPercentage;
    public $discountedPrice;

    public function mount($product_id)
    {
        $this->product = Product::find($product_id);
        $this->description = $this->product->description;
        $this->name = $this->product->name;
        $this->category_id = $this->product->category_id;
        $this->price = $this->product->price;
        $this->discountPercentage = $this->product->discount_percentage;
        $this->discountedPrice = !empty($this->product->discount_price) ? $this->product->discount_price : $this->product->price;

        $this->selling_tags = Tag::all();
    }


    public function rules()
    {
        return [
            'name' => 'required',
            'description' => 'required',
            'price' => 'required',
            'category_id' => 'required',
            'images.*' => 'image',
        ];
    }

    public function with()
    {
        return [
            'categories' => Category::all()
        ];
    }


    public function showDiscountInput()
    {
        $this->discountVisible = true;
    }


    /**
     * Add product and the product variation
     */
    public function updateProductWithVariations()
    {
        $this->validate();

        DB::beginTransaction();
        try {

            if ($this->discountedPrice) {
                if ($this->discountedPrice <= $this->price) {
                    $this->discountPercentage = round((($this->price - $this->discountedPrice) / $this->price) * 100, 2);
                    $this->price = $this->discountedPrice;
                } else {
                    throw new \Exception('Discounted Price cannot be greater than Old price');
                }
            }

            $this->updateProduct();
            $this->saveProductTags($this->product);

            //create product variation for the created product
            $this->product->update([
                'product_id' => $this->product->id,
                'price' => $this->price,
                'discount_percentage' => !empty($this->discountPercentage) ? $this->discountPercentage : 0,
                'discounted_price' => !empty($this->discountedPrice) ? $this->discountedPrice : null
            ]);


            if (!empty($this->images)) {
                foreach ($this->images as $image) {
                    try {
                        // Generate a unique name for the image
                        $name = time() . '-' . $image->getClientOriginalName();

                        // Store the image in the 'public/product' directory using Livewire's storeAs method
                        $path = $image->storeAs('product_images', $name, 'public');

                        Log::info('File stored successfully at: ' . $path);

                        ProductImage::where('product_id', $this->product->id)->delete();

                        // Store the image path in the database
                        ProductImage::create([
                            'product_id' => $this->product->id,
                            'image_url' => $path,
                        ]);

                    } catch (\Exception $e) {
                        Log::error('Error storing file: ' . $e->getMessage());
                        // Handle the exception as needed
                    }
                }
            } else {
                Log::warning('No images provided for the product variation.');
            }


            DB::commit();
            $this->reset(['category_id', 'name', 'description', 'price', 'discountPercentage', 'images']);
            $this->alert('success', 'Product updated successfully');
            $this->dispatch('productUpdated');

        } catch (Exception $exception) {
            DB::rollBack();
            $this->alert('error', $exception->getMessage());
        }
    }


    /**
     * Create a new product if it doesn't exist otherwise
     * return the product with the name
     */
    public function updateProduct()
    {
        $this->product->update([
            'category_id' => $this->category_id,
            'name' => $this->name,
            'description' => $this->description,
        ]);

    }


    /**
     * Save Product Tags if the product has any
     */
    public function saveProductTags($product)
    {
        if (!empty($this->selling_tags_ids)) {

            ProductTag::where('product_id', $this->product->id)->delete();

            foreach ($this->selling_tags_ids as $selling_tags_id) {
                ProductTag::create([
                    'product_id' => $this->product->id,
                    'tag_id' => $selling_tags_id
                ]);
            }
        }
    }


    #[On('update-selling-tag-ids')]
    public function updateSellingTagIds($selling_tags_ids)
    {
        $this->selling_tags_ids = $selling_tags_ids;
    }


}; ?>

<div class="row">
    <div class="card">
        <div class="card-header">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ __('Edit Product') }}
            </h2>
        </div>
        <div class="card-body p-4">
            <div class="row">
                <form wire:submit.prevent="updateProductWithVariations" class="mt-6 space-y-6">
                    <div class="row">
                        <div class="mb-4 col-lg-6">
                            <label for="category_id" class="form-label">Category</label>
                            <select id="category_id" wire:model="category_id" wire:change="getCategoryVariations"
                                    class="form-select">
                                <option>Select</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @error('category_id')
                            <p class="text-danger text-xs pt-1"> {{ $message }} </p>
                            @enderror                        </div>
                        <div class="mb-4 col-lg-6">
                            <label for="name" class="form-label">Name</label>
                            <input class="form-control" wire:model="name" id="email" type="text"
                                   autocomplete="name">
                            @error('name')
                            <p class="text-danger text-xs pt-1"> {{ $message }} </p>
                            @enderror                        </div>
                    </div>
                    <div class="row">
                        <div class="mb-4 col-lg-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea style="width:100%" wire:model="description" id="" cols="50" rows="5"></textarea>
                            @error('description')
                            <p class="text-danger text-xs pt-1"> {{ $message }} </p>
                            @enderror                        </div>
                    </div>
                    <div class="row">
                        <div class="mb-4 col-lg-10">
                            <label for="price" class="form-label">Price (KES)</label>
                            <input class="form-control" wire:model="price" id="price" type="number"
                                   autocomplete="price">
                            @error('price')
                            <p class="text-danger text-xs pt-1"> {{ $message }} </p>
                            @enderror
                        </div>
                        <div style="padding-top:30px;" class="col-lg-2">
                            <button type="button" class="btn btn-primary" wire:click="showDiscountInput">Add Discount
                            </button>
                        </div>
                    </div>

                    <div class="row">
                        @if($discountVisible)
                            <div class="mb-4 col-lg-12">
                                <label for="discountPrice" class="form-label">New Discounted Price (KES)<span
                                        style="font-size:11px; color:red;"> (This price will be used as the selling price)</span></label>
                                <input class="form-control" wire:model.live="discountedPrice" id="discountPrice"
                                       type="number" autocomplete="discount">
                            </div>
                        @endif
                    </div>

                    <div class="row">
                        <div wire:ignore class="mb-4 col-lg-12">
                            <label for="selling_tags" class="form-label">Shopping Tags <span
                                    style="font-size:11px; color:red;">(Selected Tags will replace the old Tags)</span></label>
                            <select id="tags" class="form-control selling_tags" wire:model="selling_tags_ids" multiple>
                                @foreach($selling_tags as $selling_tag)
                                    <option value="{{ $selling_tag->id }}">{{ $selling_tag->name }}</option>
                                @endforeach
                            </select>
                            @error('selling_tags_id')
                            <p class="text-danger text-xs pt-1"> {{ $message }} </p>
                            @enderror
                        </div>
                    </div>
                    <div class="row">
                        <div class="mb-4 col-lg-12">
                            <label for="images" class="form-label">Images <span style="font-size:11px; color:red;">(Selected Images will replace the old Images)</span></label>
                            <input accept="image/*" class="form-control" wire:model="images" id="images"
                                   type="file"
                                   autocomplete="images" multiple>
                            @error('images.*')
                            <p class="text-danger text-xs pt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-secondary waves-effect">
                        <span>Update Product</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

@push('js')
    <script>
        document.addEventListener("livewire:navigated", function () {
            const sellingTags = new Choices(".selling_tags", {removeItemButton: !0});
            sellingTags.passedElement.element.addEventListener('change', function (event) {
                const result = $('.selling_tags').val();
                Livewire.dispatch('update-selling-tag-ids', {selling_tags_ids: result});
            })

            Livewire.on('productUpdated', () => {
                document.getElementById('images').value = ''; // Clear file input
                document.getElementById('tags').value = ''; // Clear file input
            });

        })

    </script>
@endpush

