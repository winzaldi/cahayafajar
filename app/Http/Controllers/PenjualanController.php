<?php

namespace App\Http\Controllers;

use App\Models\Penjualan;
use App\Models\PenjualanHist;
use App\Models\PenjualanDetail;
use App\Models\PenjualanDetailHist;
use App\Models\Produk;
use App\Models\Setting;
use Illuminate\Http\Request;
use DB;
use PDF;

class PenjualanController extends Controller
{
    public function index()
    {
        return view('penjualan.index');
    }

    public function data()
    {
        $penjualan = Penjualan::select("*")->where('bayar','>',0)->orderBy('id_penjualan', 'desc')->get();

        return datatables()
            ->of($penjualan)
            ->addIndexColumn()
            ->addColumn('tanggal', function ($penjualan) {
                return tanggal_indonesia($penjualan->created_at, false);
            })
            ->addColumn('total_item', function ($penjualan) {
                return format_uang($penjualan->total_item);
            })
            // ->addColumn('total_harga', function ($penjualan) {
            //     return 'Rp. '. format_uang($penjualan->total_harga);
            // })
            // ->addColumn('bayar', function ($penjualan) {
            //     return 'Rp. '. format_uang($penjualan->bayar);
            // })
           
            // ->addColumn('kode_member', function ($penjualan) {
            //     $member = $penjualan->member->kode_member ?? '';
            //     return '<span class="label label-success">'. $member .'</spa>';
            //})
            // ->editColumn('diskon', function ($penjualan) {
            //     return $penjualan->diskon . '%';
            // })
            ->editColumn('kasir', function ($penjualan) {
                return $penjualan->user->name ?? '';
            })
            ->addColumn('aksi', function ($penjualan) {
                return '
                <div class="btn-group">
                    <button onclick="showDetail(`'. route('penjualan.show', $penjualan->id_penjualan) .'`)" class="btn btn-xs btn-info btn-flat"><i class="fa fa-eye"></i></button>
                    <button onclick="deleteData(`'. route('penjualan.destroy', $penjualan->id_penjualan) .'`)" class="btn btn-xs btn-danger btn-flat"><i class="fa fa-trash"></i></button>
                </div>
                ';
            })
            ->rawColumns(['aksi'])
            ->make(true);
    }

    public function create()
    {
        $penjualan = new Penjualan();
        //$penjualan->id_member = null;
        $penjualan->total_item = 0;
        //$penjualan->total_harga = 0;
        //$penjualan->diskon = 0;
        $penjualan->bayar = 0;
        $penjualan->diterima = 0;
        $penjualan->id_user = auth()->id();
        $penjualan->save();

        session(['id_penjualan' => $penjualan->id_penjualan]);
        return redirect()->route('transaksi.index')->with( ['active' => 'Baru'] );;
    }

    public function store(Request $request)
    {
        $penjualan = Penjualan::findOrFail($request->id_penjualan);
        $penjualan->total_item = $request->total_item;
        //$penjualan->total_harga = $request->total;
        $penjualan->bayar = $request->bayar;
        $penjualan->diterima = $request->diterima;
        $penjualan->update();

        //simpan history penjualan
        $penjualanhist = new PenjualanHist();
        $penjualanhist->id_penjualan = $request->id_penjualan;
        $penjualanhist->total_item = $request->total_item;
        //$penjualanhist->total_harga = $request->total;
        $penjualanhist->bayar = $request->bayar;
        $penjualanhist->id_user = auth()->id();
        $penjualanhist->diterima = $request->diterima;
        $penjualanhist->save();
        //dd($penjualanhist);

        $detail = PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->get();
        $maxHistory =  PenjualanDetailHist::where('id_penjualan', $penjualan->id_penjualan)->max('id_history');
        $detailhist = PenjualanDetailHist::where('id_history', $maxHistory)->get();
        
        //dd($detailhist);
        //if($detail->count() > $detailhist->count() ){

            foreach ($detail as $item) {
                //update detail
                $item->update();                          
                //update stok               
                $id_produk = $item->id_produk;
                $id_penjualan = $item->id_penjualan;
                if($detailhist->isNotEmpty()){
                   //dd($detailhist); 
                    if($detailhist->contains("id_produk",$item->id_produk)){

                        $ihist = $detailhist->filter(function($row) use ($id_produk){
                                      return $row->id_produk == $id_produk;
                                 })->first();
                        //dd($ihist);
                        $realCount = $item->jumlah - $ihist->jumlah;
                        $produk = Produk::find($item->id_produk);
                        $produk->stok -= $realCount;
                        //dd($realCount);
                        $produk->update();    
                    }else{
                        //jika ada tambah beli di transaksi selanjutnya
                        $produk = Produk::find($item->id_produk);
                        $produk->stok -= $item->jumlah;
                        $produk->update();            
                    }    
    
                    //cari jika ada yang dihapus
                    $itemDel = DB::table("penjualan_detail_hist")->select('*')
                                ->where('id_penjualan',"=", $item->id_penjualan)
                                ->where('id_history',"=", $maxHistory)
                                ->whereNOTIn('id_produk',function($query)use ($id_penjualan) {
                                   $query->select('id_produk')
                                           ->from('penjualan_detail')
                                           ->where("id_penjualan",$id_penjualan); 
                                })->get();    
                    
                    if($itemDel->isNotEmpty()){
                        foreach ($itemDel as $item) {
                            $produk = Produk::find($item->id_produk);
                            $produk->stok += $item->jumlah;
                            $produk->update();    
                        }  
                     }   
                }else{
                    //dd($item);
                      //jika ada tambah beli di transaksi selanjutnya
                      $produk = Produk::find($item->id_produk);
                      $produk->stok -= $item->jumlah;
                      $produk->update();
                }

                //insert detail history
                $itemHist = new  PenjualanDetailHist();
                $itemHist->id_history          = $penjualanhist->id_history;
                $itemHist->id_penjualan_detail = $item->id_penjualan_detail;
                $itemHist->id_penjualan        = $item->id_penjualan;
                $itemHist->id_produk           = $item->id_produk;
                $itemHist->harga_jual          = $item->harga_jual;
                $itemHist->jumlah              = $item->jumlah;
                $itemHist->subtotal            = $item->subtotal;
                $itemHist->save();
                //dd($itemHist);  
                       
           }

        return redirect()->route('transaksi.selesai');
    }

    public function show($id)
    {
        $detail = PenjualanDetail::with('produk')->where('id_penjualan', $id)->get();

        return datatables()
            ->of($detail)
            ->addIndexColumn()
            ->addColumn('kode_produk', function ($detail) {
                return '<span class="label label-success">'. $detail->produk->kode_produk .'</span>';
            })
            ->addColumn('nama_produk', function ($detail) {
                return $detail->produk->nama_produk;
            })
            ->addColumn('harga_jual', function ($detail) {
                return 'Rp. '. format_uang($detail->harga_jual);
            })
            ->addColumn('jumlah', function ($detail) {
                return format_uang($detail->jumlah);
            })
            ->addColumn('subtotal', function ($detail) {
                return 'Rp. '. format_uang($detail->subtotal);
            })
            ->rawColumns(['kode_produk'])
            ->make(true);
    }

    public function destroy($id)
    {
        $penjualan = Penjualan::find($id);
        $detail    = PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->get();
        foreach ($detail as $item) {
            $produk = Produk::find($item->id_produk);
            if ($produk) {
                $produk->stok += $item->jumlah;
                $produk->update();
            }

            $item->delete();
        }

        $penjualan->delete();

        return response(null, 204);
    }

    public function selesai()
    {
        $setting = Setting::first();

        return view('penjualan.selesai', compact('setting'));
    }

    public function notaKecil()
    {
        $setting = Setting::first();
        $penjualan = Penjualan::find(session('id_penjualan'));
        if (! $penjualan) {
            abort(404);
        }
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', session('id_penjualan'))
            ->get();

        return view('penjualan.nota_kecil', compact('setting', 'penjualan', 'detail'));
    }

    public function notaBesar()
    {
        $setting = Setting::first();
        $penjualan = Penjualan::find(session('id_penjualan'));
        if (! $penjualan) {
            abort(404);
        }
        $detail = PenjualanDetail::with('produk')
            ->where('id_penjualan', session('id_penjualan'))
            ->get();

        $pdf = PDF::loadView('penjualan.nota_besar', compact('setting', 'penjualan', 'detail'));
        $pdf->setPaper(0,0,609,440, 'potrait');
        return $pdf->stream('Transaksi-'. date('Y-m-d-his') .'.pdf');
    }
}
