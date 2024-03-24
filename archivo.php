<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\_manejador\RutasManejador;
use App\_manejador\ObjetosValoradosManejador;
use App\_manejador\BovedaManejador;
use App\_dto\entidades\preparacion\EnteroRemesaDto;
use App\_dto\entidades\dominio\ObjetoValoradoDto;
use App\_dto\entidades\dominio\TesoreriaStockBultosDto;
use App\_dto\entidades\dominio\TesoreriaStockMontosDto;
use App\_dto\entidades\transporte\PuntoServicioDto;
use App\_dto\entidades\transporte\RutasRemesasDto;
use App\_dto\entidades\transporte\RutasRemesasMontosEnterosDto;
use App\_manejador\ClienteManejador as ClienteManejador;
use App\_manejador\AccesoManejador as AccesoManejador;
use App\_servicios\Io;
use App\_servicios\SpreadsheetService;
use Spipu\Html2Pdf\Html2Pdf;
use Exception;
use stdClass;


class CheckInController extends AbstractController
{
    private $codigo = 200;
    private $mensaje = "Solicitud enviada correctamente";
    private $success = true;

    private $io;

    public function __construct() {
        $this->io = new Io();
    }

    #[Route('/check-in', name: 'checkin')]
    public function index(Request $request): Response
    {
        try {
            return $this->render('Checkin/CheckInVista.html.twig', [
                'rol' => strtoupper(''),
                'fechaInicial' => date("Y-m-d")

            ]);
        } catch (Exception $e) {
            return 'Error renderizando vista: ' . $e->getMessage() . ' ' .  $e->getCode();
        }
    }

    #[Route('/api/checkin/ingresa-remesa-function-monto-completo', name: 'ingresaRemesaFunctionMontoCompleto',  methods: ['POST'])]
    public function ingresaRemesaFunctionMontoCompleto(Request $request): jsonResponse
    {
        try {
            $data                   = json_decode($request->getContent(), true);
            $remito                 = $data['remito'];
            $codigoTransaccion      = str_replace(" ", "", $data['codigo_transaccion']);
            $precinto               = $data['precinto'];
            $datosMoneda            = $data['datosMoneda'];
            $datosEnteros           = $data['datosEnteros'];

            $tipo                   = $data['tipo'];
            $bultoSp                = $data['bultoSp'];
            $bultoCp                = $data['bultoCp'];

            $mRutasManejador = new RutasManejador();
            $arrayTransaccion = $mRutasManejador->recuperarListaPuntoServicioPorEstado($data['id_ruta']);
            foreach ($arrayTransaccion as $detallePuntosServicioDto) {
                if ($detallePuntosServicioDto->getContadorPuntoUnico() == $codigoTransaccion) {

                    $arraySessionParametros = array(
                        "idcodigo_transaccion"  => 'id' . $codigoTransaccion,
                        "idpunto"               => $detallePuntosServicioDto->getPunto()->getId(),
                        "id_ruta_punto"         => $detallePuntosServicioDto->getIdPuntoServicio(),
                        "remitos_recepcionados" => $detallePuntosServicioDto->getRemitosRecibidosTesoreria(),
                        "bultos_recepcionados"  => $detallePuntosServicioDto->getBultosRecibidosTesoreria(),
                        "remito_operaciones"    => $detallePuntosServicioDto->getRemitosRecibidosOperaciones(),
                        "bultos_operaciones"    => $detallePuntosServicioDto->getBultosRecibidosOperaciones()

                    );
                }
            }

            $mObjetosValoradosManejador = new ObjetosValoradosManejador();
            $mRutasManejador            = new RutasManejador();

            $idObjetoValoradoPadre      = 0;
            $idRemitoFinal              = 0;
            $validadorRemitoMonto       = 1;
            $detalleRemitoDto           = $mObjetosValoradosManejador->recuperarDetalleRemito($remito);

            $rutasRemesasDto = new RutasRemesasDto();
            $rutasRemesasDto->setIdRutasHasPuntos($arraySessionParametros['id_ruta_punto']);
            $rutasRemesasDto->setCodigoRemesa($remito);
            $mRutasManejador->crearRutasRemesas($rutasRemesasDto);

            if ($remito != "" && $codigoTransaccion != 0) {

                $objetoValoradoDto          = new ObjetoValoradoDto();
                $detalleRemitoDto           = $mObjetosValoradosManejador->recuperarDetalleRemito($objetoValoradoDto->getCodigoObjeto());
               
                $detallePuntoServicioDto    = $mRutasManejador->recuperarDetallePuntoServicio($arraySessionParametros['id_ruta_punto']);

                
                if ($detallePuntoServicioDto->getIdPuntoServicio() != null) {
                    $remitosRecepcionados = $detallePuntoServicioDto->getRemitosRecibidosTesoreria();
                    $bultosRecepcionados  = $detallePuntoServicioDto->getBultosRecibidosTesoreria();
                }

                if (
                    $arraySessionParametros['remito_operaciones'] == 1
                    && $arraySessionParametros['bultos_operaciones'] == 1
                ) {
                    $puntoServicioDto = $mRutasManejador->recuperarPuntoServicioOP($detallePuntoServicioDto->getIdPuntoServicio());
                    $puntoServicioDto->setCheckTesoreria(1);
                    $mRutasManejador->modificarPuntoServicio($puntoServicioDto);
                } else {
                    $puntoServicioDto = $mRutasManejador->recuperarPuntoServicioOP($detallePuntoServicioDto->getIdPuntoServicio());
                    $puntoServicioDto->setCheckTesoreria(0);
                    $mRutasManejador->modificarPuntoServicio($puntoServicioDto);
                }


                if (($remitosRecepcionados > 0 || $bultosRecepcionados > 0)
                    && $remitosRecepcionados == $arraySessionParametros['remitos_recepcionados']
                    && $bultosRecepcionados  == $arraySessionParametros['bultos_recepcionados']
                ) {

                    $puntoServicioDto = $mRutasManejador->recuperarPuntoServicioOP($detallePuntoServicioDto->getIdPuntoServicio());

                   
                    $puntoServicioDto->setCheckTesoreria(1);
                    $mRutasManejador->modificarPuntoServicio($puntoServicioDto);
                }

                $detalleRemitoDto = $mObjetosValoradosManejador->recuperarDetalleRemito($remito);

              
                if ($detalleRemitoDto->getIdObjetoValorado() != null) {

                    $idRemitoFinal          = $detalleRemitoDto->getIdObjetoValorado();
                    $idObjetoValoradoPadre  = $idRemitoFinal;
                } else {
                    $permitido = 1;

                    if ($permitido == 1) {

                        $objetoValoradoDto = new ObjetoValoradoDto();
                        $objetoValoradoDto->setPuntoRetiro($arraySessionParametros['idpunto']);
                        $objetoValoradoDto->setPuntoRemesa($arraySessionParametros['idpunto']);
                        $objetoValoradoDto->setUsuarioRecepcion($data['idUsuario']);
                        $objetoValoradoDto->setTipoObjetoValorado(5);
                        $objetoValoradoDto->setCodigoObjeto($remito);
                        $objetoValoradoDto->setEstadoObjetoValorado(10);
                        $objetoValoradoDto->setIdEstado(1);
                        $objetoValoradoDto->setFechaHoraIngresoRecepcion(date("Y-m-d h:i:s"));
                        $objetoValoradoDto->setPuntoServicioEntrada($arraySessionParametros['id_ruta_punto']);
                        $objetoValoradoDto->setFechaRecepcion(date("Y-m-d h:i:s"));
                        $objetoValoradoDto->getDevolucion(1);
                        
                        if ($tipo == 1) {
                            $objetoValoradoDto->setBultosCp(1);
                        } else {
                            $objetoValoradoDto->setBultosCp($bultoCp);
                        }
                        $objetoValoradoDto->setBultosSp($bultoSp);

                        $idObjetoValoradoPadre = $mObjetosValoradosManejador->InsertarBulto($objetoValoradoDto);

                        $detalleRemitoDto  = $mObjetosValoradosManejador->recuperarDetalleRemito($remito);
                        $idRemitoFinal   = ($detalleRemitoDto->getIdObjetoValorado() != null) ? $detalleRemitoDto->getIdObjetoValorado() : $idRemitoFinal;
                        $puntoServicioDto = $mRutasManejador->recuperarPuntoServicioOP($arraySessionParametros['id_ruta_punto']);

                        $puntoServicioDto->setRemitosRecepcionados($puntoServicioDto->getRemitosRecepcionados() + 1);
                        $mRutasManejador->modificarPuntoServicio($puntoServicioDto);

                        $PuntoServicioDto = new PuntoServicioDto();
                        $PuntoServicioDto->setRemitosRecepcionados($PuntoServicioDto->getremitosRecepcionados() + 1);

                        $mRutasManejador->modificarPuntoServicio($PuntoServicioDto);
                       /* $objetoValoradoDto = $mObjetosValoradosManejador->recuperarObjetosValoradoOP($detalleRemitoDto->getIdObjetoValorado());
                        $objetoValoradoDto->getDevolucion(1);
                        $objetoValoradoDto->setFechaHoraIngresoRecepcion(date("Y-m-d h:i:s"));
                        $objetoValoradoDto->setPuntoServicioEntrada($arraySessionParametros['id_ruta_punto']);
                        dd($objetoValoradoDto);
                        //$mObjetosValoradosManejador->modificarObjetoValorado($objetoValoradoDto);*/
                        $validadorRemitoMonto = 1;
                    } else {
                        $validadorRemitoMonto = 2;
                    }
                }


                $detalleBultoDto  = $mObjetosValoradosManejador->recuperarDetalleBulto($precinto);
                if ($detalleBultoDto->getIdObjetoValorado() != null) {
                    $permitido = 2;
                } else {

                    if (!empty($precinto)) {
                        $permitido = 1;
                        $objetoValoradoDto = new ObjetoValoradoDto();
                        $objetoValoradoDto->setPuntoRetiro($arraySessionParametros['idpunto']);
                        $objetoValoradoDto->setPuntoRemesa($arraySessionParametros['idpunto']);
                        $objetoValoradoDto->setUsuarioRecepcion($data['idUsuario']);
                        $objetoValoradoDto->setObjetoPadre($idRemitoFinal);
                        $objetoValoradoDto->setTipoObjetoValorado(6);
                        $objetoValoradoDto->setCodigoObjeto($precinto);
                        $objetoValoradoDto->setEstadoObjetoValorado(10);
                        $objetoValoradoDto->setIdEstado(1);
                        $objetoValoradoDto->setFechaHoraIngresoRecepcion(date("Y-m-d h:i:s"));
                        $objetoValoradoDto->setPuntoServicioEntrada($arraySessionParametros['id_ruta_punto']);
                        $objetoValoradoDto->setFechaRecepcion(date("Y-m-d h:i:s"));
                       
                        $mObjetosValoradosManejador->InsertarBulto($objetoValoradoDto);
                        $idObjetoValoradoPadre = $idRemitoFinal;
                    }

                    if ($validadorRemitoMonto == 1 && !empty($precinto)) {
                        $puntoServicioDto = $mRutasManejador->recuperarPuntoServicioOP($arraySessionParametros['id_ruta_punto']);
                        $puntoServicioDto->setBultosRecepcionados($puntoServicioDto->getBultosRecepcionados() + 1);
                        $mRutasManejador->modificarPuntoServicio($puntoServicioDto);
                    }
                }
            }

            switch ($tipo) {
                case 1:
                    //$puntoServicioDto->setBultosCp(1);
                    $puntoServicioDto->setId($arraySessionParametros['id_ruta_punto']);
                    $mRutasManejador->modificarPuntoServicioBultoCp($puntoServicioDto);
                    break;
                case 2:
                    $observacion = 'Bolsas de enteros pendientes por recepcionar';
                    $puntoServicioDto  = $mRutasManejador->recuperarPuntoServicioOP($arraySessionParametros['id_ruta_punto']);
                    $puntoServicioDto->setObservacion(1);
                    $mRutasManejador->insertarPuntosJustificadoOP($observacion, $arraySessionParametros['id_ruta_punto']);
                    $puntoServicioDto = $mRutasManejador->recuperarPuntoServicioOP($detallePuntoServicioDto->getIdPuntoServicio());
                    $puntoServicioDto->setCheckTesoreria(1);
                    $mRutasManejador->modificarPuntoServicio($puntoServicioDto);
                    break;
                case 3:
                    $puntoServicioDto = $mRutasManejador->recuperarPuntoServicioOP($detallePuntoServicioDto->getIdPuntoServicio());
                    $puntoServicioDto->setCheckTesoreriaMonedas(1);
                    $mRutasManejador->modificarPuntoServicio($puntoServicioDto);
                    $puntoServicioJustificadoDto =  $mRutasManejador->recuperarPuntosJustificadoPorRuta($arraySessionParametros['id_ruta_punto']);
                    if ($puntoServicioJustificadoDto != null) {
                        $PuntoServicioDto  = $mRutasManejador->recuperarPuntoServicioOP($arraySessionParametros['id_ruta_punto']);
                        $mRutasManejador->eliminarPuntosJustificadoOP($puntoServicioJustificadoDto->getId());
                    }

                    break;
            }

            if (!empty($precinto)) {
                $arrayDetalleMontosRemitoDto    = $mObjetosValoradosManejador->recuperarListaDetalleMontosRemitoTemporales($remito, $precinto);
            } else {
                $arrayDetalleMontosRemitoDto    = $mObjetosValoradosManejador->recuperarListaDetalleMontosRemitoTemporales($remito, null);
            }

            $detalleRemitoDto               = $mObjetosValoradosManejador->recuperarDetalleRemito($remito);
            $idRemitoFinal                  = $detalleRemitoDto->getIdObjetoValorado();
            $totalMonedas = 0;
            if (count($datosEnteros) > 0) {
                $this->modificacionTipoMontoVarios($idRemitoFinal, 2, $datosEnteros[0]['entero_500']);
                $this->modificacionTipoMontoVarios($idRemitoFinal, 3, $datosEnteros[0]['entero_100']);
                $this->modificacionTipoMontoVarios($idRemitoFinal, 4, $datosEnteros[0]['entero_50']);
                $this->modificacionTipoMontoVarios($idRemitoFinal, 12,  $datosEnteros[0]['entero_10']);
                $this->modificacionTipoMontoVarios($idRemitoFinal, 13,  $datosEnteros[0]['entero_5']);
                $this->modificacionTipoMontoVarios($idRemitoFinal, 14,  $datosEnteros[0]['entero_1']);

                $totalMonedas = $datosMoneda[0]['moneda_500'] + $datosMoneda[0]['moneda_100'] + $datosMoneda[0]['moneda_50'] + $datosMoneda[0]['moneda_10'] +  $datosMoneda[0]['moneda_5'] + $datosMoneda[0]['moneda_1'];
                $this->modificacionTipoMonto($idRemitoFinal, $totalMonedas, 13);

                $permitido = 1;
            }// else {

                foreach ($arrayDetalleMontosRemitoDto as $detalleMontosRemitoDto) {
                    $tipoValorRemitoDto = $mObjetosValoradosManejador->recuperarMontoRemitoOP($idRemitoFinal, $detalleMontosRemitoDto->getIdTipoValorRemito());

                    if ($tipoValorRemitoDto->getObjetoValorado() == 0) {
                        if ($detalleMontosRemitoDto->getMonto() != null || $detalleMontosRemitoDto->getMonto() != "" || $detalleMontosRemitoDto->getMonto() > 0) {
                            $mObjetosValoradosManejador->insertarMontoARemito($detalleMontosRemitoDto->getMonto(), $detalleMontosRemitoDto->getIdTipoValorRemito(), $idRemitoFinal);
                        }
                    }
                }
            //}

            $this->ingresaTesoreriaStock($idObjetoValoradoPadre, $remito);

            $objeto = new stdClass();
            $objeto->idRemitoFinal = $idRemitoFinal;
            $objeto->permitido = $permitido;

            $this->io->setDatos($objeto);

        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }
        return $this->json($this->io);
    }

    #[Route('/getImpresionRutaTesoreria/{idRuta}/{fecha}', name: 'impresionRutaTesoreriaCheckin',  methods: ['GET'])]
    public function impresionRutaTesoreriaCheckin(Request $request, $idRuta, $fecha)
    {
        try {
            $pdf = new Html2Pdf('L', 'Legal');

            $html = "";
            $rutaId = "-1";
            if (isset($idRuta)) {
                $rutaId = $idRuta;
            }

            $mRutasManejador             = new RutasManejador();
            $mObjetosValoradosManejador  = new ObjetosValoradosManejador();
            $accesoManejador             = new AccesoManejador();

            $numeroRutaFinal        = "";
            $nombreUnidadMovil      = "";
            $patenteUnidadMovil     = "";
            $fechaInicioCheckIn     = "";
            $fechaTerminoCheckIn    = "";
            $nombreUsuario          = "";
            $nombreApellidoUsuario  = "";

            $rutaDto                = $mRutasManejador->recuperarRutaOP($rutaId);
            if ($rutaDto != null) {
                if (substr_count($rutaDto->getNumeroRuta(), "RUTA") > 0) {
                    $numeroRutaFinal = str_replace("RUTA", "", $rutaDto->getNumeroRuta());
                } else {
                    $numeroRutaFinal = $rutaDto->getNumeroRuta();
                }

                $PersonaDto =  $mRutasManejador->recuperarPersona($rutaDto->getPortavalor());
                if ($PersonaDto != null) {
                    $nombreApellidoUsuario = $PersonaDto->getNombrePersona() . " " . $PersonaDto->getApellidoPaternoPersona();
                }

                $VehiculoDto =  $mRutasManejador->recuperarVehiculo($rutaDto->getCamion());
                if ($VehiculoDto != null) {
                    $nombreUnidadMovil  = $VehiculoDto->getNombreVehiculo();
                    $patenteUnidadMovil = $VehiculoDto->getPatenteVehiculo();
                }

                $arrayPuntoServicio  = $mRutasManejador->PuntoServicio(null, $rutaDto->getId());
                foreach ($arrayPuntoServicio as $puntoServicio) {
                    $arrayObjetosValorado =  $mRutasManejador->recuperarRemesasPorPuntoServicio($puntoServicio->getId());
                    if (count($arrayObjetosValorado) > 0) {
                        $objetosValorado = $arrayObjetosValorado[0];
                        $fechaInicioCheckIn =  substr($objetosValorado->getFechaHoraIngresoRecepcion(), 11, 8);
                        $fechaTerminoCheckIn =  substr($objetosValorado->getFechaHoraIngresoRecepcion(), 11, 8);

                        $Usuario = $accesoManejador->Usuario($objetosValorado->getUsuarioRecepcion());
                        if ($Usuario != null) {
                            $nombreUsuario = $Usuario->getNombrePersona();
                        }
                        break;
                    }
                }
            }
            $z = 1;
            $nombre_mes[1] = "Enero";
            $nombre_mes[2] = "Febrero";
            $nombre_mes[3] = "Marzo";
            $nombre_mes[4] = "Abril";
            $nombre_mes[5] = "Mayo";
            $nombre_mes[6] = "Junio";
            $nombre_mes[7] = "Julio";
            $nombre_mes[8] = "Agosto";
            $nombre_mes[9] = "Septiembre";
            $nombre_mes[10] = "Octubre";
            $nombre_mes[11] = "Noviembre";
            $nombre_mes[12] = "DIciembre";
            $nombre_dia[0] = "Domingo";
            $nombre_dia[1] = "Lunes";
            $nombre_dia[2] = "Martes";
            $nombre_dia[3] = "Miercoles";
            $nombre_dia[4] = "Jueves";
            $nombre_dia[5] = "Viernes";
            $nombre_dia[6] = "Sabado";

            $fechaFind       = $rutaDto->getFecha();
            $fechats          = strtotime($fechaFind);
            $mesActual       = substr($fechaFind, 5, 2);
            $diaActualFinal = substr($fechaFind, 8, 2);
            $yearActual      = substr($fechaFind, 0, 4);

            switch (date('w', $fechats)) {
                case 0:
                    $diaName = 0;
                    break; //Domingo
                case 1:
                    $diaName = 1;
                    break; //Lunes
                case 2:
                    $diaName = 2;
                    break; //Martes
                case 3:
                    $diaName = 3;
                    break; //Miercoles
                case 4:
                    $diaName = 4;
                    break; //Jueves
                case 5:
                    $diaName = 5;
                    break; //Viernes
                case 6:
                    $diaName = 6;
                    break; //Sabado
            }

            switch ($mesActual) {
                case '01':
                    $mesAc = 1;
                    break;
                case '02':
                    $mesAc = 2;
                    break;
                case '03':
                    $mesAc = 3;
                    break;
                case '04':
                    $mesAc = 4;
                    break;
                case '05':
                    $mesAc = 5;
                    break;
                case '06':
                    $mesAc = 6;
                    break;
                case '07':
                    $mesAc = 7;
                    break;
                case '08':
                    $mesAc = 8;
                    break;
                case '09':
                    $mesAc = 9;
                    break;
                default:
                    $mesAc = $mesActual;
                    break;
            }

            $totalBultos = 0;
            $totalPuntos = 0;
            $totalPuntosHechos = 0;
            $totalPuntosNoRealizados = 0;

            $mensaje = "";
            $totalRemitos = 0;
            $rutaMensaje = "";

            $arrayDetallePuntosServicioDto = $mRutasManejador->recuperarListaPuntoServicioPorRuta($rutaId);
            $totalPuntos = count($arrayDetallePuntosServicioDto);

            $i = 0;
            foreach ($arrayDetallePuntosServicioDto as $detallePuntosServicio) {
                $puntoServicioDto = $mRutasManejador->PuntoServicio($detallePuntosServicio->getIdPuntoServicio());
                $totalPuntosHechos = $totalPuntosHechos + $detallePuntosServicio->getRecepcionadoOperaciones();
                $totalPuntosNoRealizados =  $totalPuntosNoRealizados + 0;

                $ultimaHora = date("Y-m-d");
                if (substr_count($numeroRutaFinal, "ATM") > 0) {
                    $rutaMensaje = strtoupper($detallePuntosServicio->getPunto()->getNombrePunto());
                } else {
                    $rutaMensaje = strtoupper($detallePuntosServicio->getPunto()->getDireccionPunto());
                }
                if ($detallePuntosServicio->getMovimiento() == "ENTREGA") {
                    $mensaje = "ENT";
                } elseif ($detallePuntosServicio->getMovimiento() == "RETIRO") {
                    $mensaje = "RET";
                } elseif ($detallePuntosServicio->getMovimiento() == "ENTREGA/RETIRO") {
                    $mensaje = "ENT/RET";
                } else {
                    $mensaje = $detallePuntosServicio->getMovimiento();
                }
                $arrayObjetoValorado = $mRutasManejador->recuperarRemesasPorPuntoServicio($detallePuntosServicio->getIdPuntoServicio());

                $contadorBolsas = 0;
                $contadorRemitos = 0;
                $remito = "";
                if (count($arrayObjetoValorado) > 0) {
                    $contadorBolsas = 0;
                    $contadorRemitos = 0;
                    $cont = 0;
                    $remito = "";
                    foreach ($arrayObjetoValorado as $objetoValoradoDto) {
                        $contadorRemitos++;
                        $detalleRemitoDto = $mObjetosValoradosManejador->recuperarDetalleRemito(null, $objetoValoradoDto->getId());
                        if ($detalleRemitoDto != null) {
                            if ($detalleRemitoDto->getIdObjetoValorado() != null) {

                                $contadorBolsas = $contadorBolsas + count($detalleRemitoDto->getListaBultos());
                                $cont++;
                                if ($cont == 1) {
                                    $remito .= $detalleRemitoDto->getCodigoRemito();
                                } else {
                                    $remito .= " - " . $detalleRemitoDto->getCodigoRemito();
                                }
                            }
                        }
                    }
                }
                $totalBultos  = $totalBultos  + $contadorBolsas;
                $totalRemitos = $totalRemitos + $contadorRemitos;


                if ($contadorBolsas == 0) {
                    $contadorBolsas = "";
                }

                if ($contadorRemitos == 0) {
                    $contadorRemitos = "";
                }
                $z++;
                $datos[$i] = array(

                    'NUMERO_RUTA_FINAL'     => substr_count($numeroRutaFinal, "ATM"),
                    'MENSAJE'               => $mensaje,
                    'TOTAL_BULTOS'          => $totalBultos,
                    'TOTAL_REMITOS'         => $totalRemitos,
                    'CONTADOR_BOLSAS'       => $contadorBolsas,
                    'CONTADOR_REMITOS'      => $contadorRemitos,
                    'remito'                => $remito,
                    'contadorPunto'         => $puntoServicioDto->getContadorPunto(),
                    'nombreCliente'         => strtoupper($detallePuntosServicio->getCliente()->getNombreCliente()),
                    'RUTA_MENSAJE'          => $rutaMensaje,
                );
                $i++;
            }


            $fecha = $ultimaHora;
            $nuevafecha = strtotime('+30 minute', strtotime($fecha));
            $nuevafecha = date('H:i', $nuevafecha);
            $html .= $this->render('impresionRuta/index.html.twig', [

                'NUMERO_RUTA_FINAL'                     => strtoupper($numeroRutaFinal),
                'DIA'                                   => strtoupper($nombre_dia[$diaName]),
                'DIA_ACTUAL'                            => $diaActualFinal,
                'MES'                                   => strtoupper($nombre_mes[$mesAc]),
                'ANIO'                                  => $yearActual,
                'NOMBRE_APELLIDO_USUARIO'               => $nombreApellidoUsuario,
                'NOMBRE_USUARIO'                        => $nombreUsuario,
                'FECHA_INICIO_CHECK_IN'                 => $fechaInicioCheckIn,
                'FECHA_TERMINO_CHECK_IN'                => $fechaTerminoCheckIn,
                'fecha_find'                            => $fechaFind,
                'seccion'                               => 'ADMIN',
                'row_puntos'                            => $datos,
                'fecha'                                 => $fecha,
                'nuevafecha'                            => $nuevafecha,
                'TOTAL_BULTOS'                          => $totalBultos,
                'TOTAL_REMITOS'                         => $totalRemitos,
                'total_puntos'                          => $totalPuntos,
                'patente_de_unidad_movil'               => $patenteUnidadMovil,
                'nombre_de_unidad_movil'                => $nombreUnidadMovil,
                'fechaInforme'                          => date("Y-m-d")
            ]);

            $pdf->writeHTML($html, true, false, true, false, '');
            ob_end_clean();
            return new Response($pdf->Output('prioridadOperaciones.pdf', 'I'));
        } catch (Exception $e) {
            return 'Error al imprimir el documento: ' . $e->getMessage();
        }
    }

    #[Route('/getExportarRutaNormal/{idRuta}/{numeroRuta}', name: 'getExportarRutaNormal',  methods: ['GET'])]
    public function exportarRutaNormal(Request $request, $idRuta)
    {
        try {
            $rutaId = "-1";
            if (isset($idRuta)) {
                $rutaId = $idRuta;
            }

            $mRutasManejador             = new RutasManejador();
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();
            $accesoManejador = new AccesoManejador();
            $mClienteManejador = new ClienteManejador();

            $numeroRutaFinal        = "";
            $nombreUnidadMovil      = "";
            $patenteUnidadMovil     = "";
            $fechaInicioCheckIn     = "";
            $fechaTerminoCheckIn    = "";
            $nombreUsuario          = "";
            $nombreApellidoUsuario  = "";

            $rutaDto                = $mRutasManejador->recuperarRutaOP($rutaId);

            if ($rutaDto != null) {
                if (substr_count($rutaDto->getNumeroRuta(), "RUTA") > 0) {
                    $numeroRutaFinal = str_replace("RUTA", "", $rutaDto->getNumeroRuta());
                } else {
                    $numeroRutaFinal = $rutaDto->getNumeroRuta();
                }

                $PersonaDto =  $mRutasManejador->recuperarPersona($rutaDto->getPortavalor());
                if ($PersonaDto != null) {
                    $nombreApellidoUsuario = $PersonaDto->getNombrePersona() . " " . $PersonaDto->getApellidoPaternoPersona();
                }

                $VehiculoDto =  $mRutasManejador->recuperarVehiculo($rutaDto->getCamion());
                if ($VehiculoDto != null) {
                    $nombreUnidadMovil  = $VehiculoDto->getNombreVehiculo();
                    $patenteUnidadMovil = $VehiculoDto->getPatenteVehiculo();
                }

                $arrayPuntoServicio  = $mRutasManejador->PuntoServicio(null, $rutaDto->getId());
                foreach ($arrayPuntoServicio as $puntoServicio) {
                    $arrayObjetosValorado =  $mRutasManejador->recuperarRemesasPorPuntoServicio($puntoServicio->getId());
                    if (count($arrayObjetosValorado) > 0) {
                        $objetosValorado = $arrayObjetosValorado[0];
                        $fechaInicioCheckIn =  substr($objetosValorado->getFechaHoraIngresoRecepcion(), 11, 8);
                        $fechaTerminoCheckIn =  substr($objetosValorado->getFechaHoraIngresoRecepcion(), 11, 8);

                        $Usuario = $accesoManejador->Usuario($objetosValorado->getUsuarioRecepcion());
                        if ($Usuario != null) {
                            $nombreUsuario = $Usuario->getNombrePersona();
                        }
                        break;
                    }
                }
            }
            $z = 1;
            $nombre_mes[1] = "Enero";
            $nombre_mes[2] = "Febrero";
            $nombre_mes[3] = "Marzo";
            $nombre_mes[4] = "Abril";
            $nombre_mes[5] = "Mayo";
            $nombre_mes[6] = "Junio";
            $nombre_mes[7] = "Julio";
            $nombre_mes[8] = "Agosto";
            $nombre_mes[9] = "Septiembre";
            $nombre_mes[10] = "Octubre";
            $nombre_mes[11] = "Noviembre";
            $nombre_mes[12] = "DIciembre";

            $nombre_dia[0] = "Domingo";
            $nombre_dia[1] = "Lunes";
            $nombre_dia[2] = "Martes";
            $nombre_dia[3] = "Miercoles";
            $nombre_dia[4] = "Jueves";
            $nombre_dia[5] = "Viernes";
            $nombre_dia[6] = "Sabado";

            $fechaFind       = $rutaDto->getFecha();
            $fechats          = strtotime($fechaFind);
            $mesActual       = substr($fechaFind, 5, 2);
            $diaActualFinal = substr($fechaFind, 8, 2);
            $yearActual      = substr($fechaFind, 0, 4);

            switch (date('w', $fechats)) {
                case 0:
                    $diaName = 0;
                    break; //Domingo
                case 1:
                    $diaName = 1;
                    break; //Lunes
                case 2:
                    $diaName = 2;
                    break; //Martes
                case 3:
                    $diaName = 3;
                    break; //Miercoles
                case 4:
                    $diaName = 4;
                    break; //Jueves
                case 5:
                    $diaName = 5;
                    break; //Viernes
                case 6:
                    $diaName = 6;
                    break; //Sabado
            }

            switch ($mesActual) {
                case '01':
                    $mesAc = 1;
                    break;
                case '02':
                    $mesAc = 2;
                    break;
                case '03':
                    $mesAc = 3;
                    break;
                case '04':
                    $mesAc = 4;
                    break;
                case '05':
                    $mesAc = 5;
                    break;
                case '06':
                    $mesAc = 6;
                    break;
                case '07':
                    $mesAc = 7;
                    break;
                case '08':
                    $mesAc = 8;
                    break;
                case '09':
                    $mesAc = 9;
                    break;
                default:
                    $mesAc = $mesActual;
                    break;
            }
            
            $totalBultos = 0;
            $totalPuntos = 0;
            $totalPuntosHechos = 0;
            $totalPuntosNoRealizados = 0;

            $mensaje = "";
            $totalRemitos = 0;
            $rutaMensaje = "";

            $arrayDetallePuntosServicioDto = $mRutasManejador->recuperarListaPuntoServicioPorRuta($rutaId);
            $totalPuntos = count($arrayDetallePuntosServicioDto);

            $i = 0;
            foreach ($arrayDetallePuntosServicioDto as $detallePuntosServicio) {
                $puntoServicioDto = $mRutasManejador->PuntoServicio($detallePuntosServicio->getIdPuntoServicio());
                $totalPuntosHechos = $totalPuntosHechos + $detallePuntosServicio->getRecepcionadoOperaciones();
                $totalPuntosNoRealizados =  $totalPuntosNoRealizados + 0;

                $ultimaHora = date("Y-m-d");
                if (substr_count($numeroRutaFinal, "ATM") > 0) {
                    $rutaMensaje = strtoupper($detallePuntosServicio->getPunto()->getNombrePunto());
                } else {
                    $rutaMensaje = strtoupper($detallePuntosServicio->getPunto()->getDireccionPunto());
                }
                if ($detallePuntosServicio->getMovimiento() == "ENTREGA") {
                    $mensaje = "ENT";
                } elseif ($detallePuntosServicio->getMovimiento() == "RETIRO") {
                    $mensaje = "RET";
                } elseif ($detallePuntosServicio->getMovimiento() == "ENTREGA/RETIRO") {
                    $mensaje = "ENT/RET";
                } else {
                    $mensaje = $detallePuntosServicio->getMovimiento();
                }
                $arrayObjetoValorado = $mRutasManejador->recuperarRemesasPorPuntoServicio($detallePuntosServicio->getIdPuntoServicio());

                $contadorBolsas = 0;
                $contadorRemitos = 0;
                $remito = "";
                if (count($arrayObjetoValorado) > 0) {
                    $contadorBolsas = 0;
                    $contadorRemitos = 0;
                    $cont = 0;
                    $remito = "";
                    foreach ($arrayObjetoValorado as $objetoValoradoDto) {
                        $contadorRemitos++;
                        $detalleRemitoDto = $mObjetosValoradosManejador->recuperarDetalleRemito(null, $objetoValoradoDto->getId());
                        if ($detalleRemitoDto != null) {
                            if ($detalleRemitoDto->getIdObjetoValorado() != null) {

                                $contadorBolsas = $contadorBolsas + count($detalleRemitoDto->getListaBultos());
                                $cont++;
                                if ($cont == 1) {
                                    $remito .= $detalleRemitoDto->getCodigoRemito();
                                } else {
                                    $remito .= " - " . $detalleRemitoDto->getCodigoRemito();
                                }
                            }
                        }
                    }
                }
                $totalBultos  = $totalBultos  + $contadorBolsas;
                $totalRemitos = $totalRemitos + $contadorRemitos;


                if ($contadorBolsas == 0) {
                    $contadorBolsas = "";
                }

                if ($contadorRemitos == 0) {
                    $contadorRemitos = "";
                }
                $z++;
                $datos[$i] = array(

                    'NUMERO_RUTA_FINAL'     => substr_count($numeroRutaFinal, "ATM"),
                    'MENSAJE'               => $mensaje,
                    'TOTAL_BULTOS'          => $totalBultos,
                    'TOTAL_REMITOS'         => $totalRemitos,
                    'CONTADOR_BOLSAS'       => $contadorBolsas,
                    'CONTADOR_REMITOS'      => $contadorRemitos,
                    'remito'                => $remito,
                    'contadorPunto'         => $puntoServicioDto->getContadorPunto(),
                    'nombreCliente'         => strtoupper($detallePuntosServicio->getCliente()->getNombreCliente()),
                    'RUTA_MENSAJE'          => $rutaMensaje,
                );
                $i++;
            }
            $fecha = $ultimaHora;
            $nuevafecha = strtotime('+30 minute', strtotime($fecha));
            $nuevafecha = date('H:i', $nuevafecha);
            $datosArray = [

                'NUMERO_RUTA_FINAL'                     => strtoupper($numeroRutaFinal),
                'DIA'                                   => strtoupper($nombre_dia[$diaName]),
                'DIA_ACTUAL'                            => $diaActualFinal,
                'MES'                                   => strtoupper($nombre_mes[$mesAc]),
                'ANIO'                                  => $yearActual,
                'NOMBRE_APELLIDO_USUARIO'               => $nombreApellidoUsuario,
                'NOMBRE_USUARIO'                        => $nombreUsuario,
                'FECHA_INICIO_CHECK_IN'                 => $fechaInicioCheckIn,
                'FECHA_TERMINO_CHECK_IN'                => $fechaTerminoCheckIn,
                'fecha_find'                            => $fechaFind,
                'seccion'                               => 'ADMIN',
                'row_puntos'                            => $datos,
                'fecha'                                 => $fecha,
                'nuevafecha'                            => $nuevafecha,
                'TOTAL_BULTOS'                          => $totalBultos,
                'TOTAL_REMITOS'                         => $totalRemitos,
                'total_puntos'                          => $totalPuntos,
                'patente_de_unidad_movil'               => $patenteUnidadMovil,
                'nombre_de_unidad_movil'                => $nombreUnidadMovil,
                'fechaInforme'                          => date("Y-m-d")

            ];

            $creacionExcel = new SpreadsheetService();
            $datosExcel = $creacionExcel->excelCargaRuta($datosArray);
            return $datosExcel;
        } catch (Exception $e) {
            return 'Error al imprimir el documento: ' . $e->getMessage();
        }
    }

    public function ingresaTesoreriaStock($idObjetoValorado, $codigoRemito)
    {
        try {
            $mObjetosValoradosManejador     = new ObjetosValoradosManejador();
            $listaDetalleMontosRemitoDto    = $mObjetosValoradosManejador->recuperarListaDetalleMontoRemito($codigoRemito);
            foreach ($listaDetalleMontosRemitoDto as $DetalleMontosRemitoDto) {
                $listaTesoreriaStockBultos    = $mObjetosValoradosManejador->recuperarListaTesoreriaStockBultos(null, $idObjetoValorado);
                if (count($listaTesoreriaStockBultos)    >   0) {
                    foreach ($listaTesoreriaStockBultos as $TesoreriaStockBultos) {
                        if ($TesoreriaStockBultos->getTipoMovimiento()   ==  1) {
                            $TesoreriaStockBultos->setBultos($TesoreriaStockBultos->getBultos() + 1);
                            $mObjetosValoradosManejador->modificarTesoreriaStockBultos($TesoreriaStockBultos);
                        }
                    }
                } else {
                    $tesoreriaStockBultosDto = new TesoreriaStockBultosDto();
                    $tesoreriaStockMontosDto = new TesoreriaStockMontosDto();

                    $tesoreriaStockBultosDto->setCodigoRemito($codigoRemito);
                    $tesoreriaStockBultosDto->setBultos(1);
                    $tesoreriaStockBultosDto->setFecha(date("Y-m-d"));
                    $tesoreriaStockBultosDto->setTipoMovimiento(1);
                    $tesoreriaStockBultosDto->setIdObjetoValorado($idObjetoValorado);
                    $correcto = $mObjetosValoradosManejador->insertarTesoreriaStockBultos($tesoreriaStockBultosDto);
                    if ($correcto) {
                        $tesoreriaStockMontosDto->setMonto($DetalleMontosRemitoDto->getMonto());
                        $tesoreriaStockMontosDto->setIdTipoValores($DetalleMontosRemitoDto->getTipoValor()->getId());
                        $tesoreriaStockMontosDto->setFecha(date("Y-m-d"));
                        $tesoreriaStockMontosDto->setTipoMovimiento(1);
                        $tesoreriaStockMontosDto->setIdObjetoValorado($idObjetoValorado);
                        $mObjetosValoradosManejador->insertarTesoreriaStockMontos($tesoreriaStockMontosDto);
                    }
                }
            }
        } catch (Exception $e) {
            return 'Error en: ' . __METHOD__ . ' ' . $e->getMessage();
        }
    }


    public function modificacionTipoMontoVarios($idRemito, $tipoValor, $monto)
    {
        try {
            $mObjetosValoradosManejador  = new ObjetosValoradosManejador();
            $EnteroRemesa = new EnteroRemesaDto();
            $DetalleEnteroRemesaDto = $mObjetosValoradosManejador->recuperarEnteroRemesa(null, $tipoValor, $idRemito);

            if ($DetalleEnteroRemesaDto != null) {

                $EnteroRemesa->setId($DetalleEnteroRemesaDto->getIdEnteroRemesa());
                $EnteroRemesa->setTipoEntero($DetalleEnteroRemesaDto->getTipoEntero()->getId());
                $EnteroRemesa->setObjetoValorado($DetalleEnteroRemesaDto->getIdObjetoValorado());
                $EnteroRemesa->setCantidad($monto);
                $mObjetosValoradosManejador->modificarEnteroRemesa($EnteroRemesa);
            } else if ($DetalleEnteroRemesaDto == null && $monto > 0) {
                $mObjetosValoradosManejador->insertarEnteroRemesa($idRemito, $tipoValor, $monto);
            }
        } catch (Exception $e) {
            return 'Error en: ' . __METHOD__ . ' ' . $e->getMessage();
        }
    }


    public function modificacionTipoMonto($idRemito = null, $formaValor = null, $tipoValor = null)
    {
        try {
            $mObjetosValoradosManejador  = new ObjetosValoradosManejador();
            $TipoValorRemitoDto = $mObjetosValoradosManejador->recuperarMontoRemitoOP($idRemito, $tipoValor);
            $montoDeclarado = str_replace('.00', '', $TipoValorRemitoDto->getMontoDeclarado());

            if ($montoDeclarado > 0) {
                $TipoValorRemitoDto->setMontoDeclarado($formaValor);
                $mObjetosValoradosManejador->modificarTipoValorRemito($TipoValorRemitoDto);
            } else if ($montoDeclarado  == 0 && $formaValor > 0) {

                $mObjetosValoradosManejador->insertarMontoARemito($formaValor, $tipoValor, $idRemito);
            }
        } catch (Exception $e) {
            return 'Error en: ' . __METHOD__ . ' ' . $e->getMessage();
        }
    }

    public function crearRutasRemesasMontosEnteros($rutaRemesa, $idTipoEntero, $cantidad)
    {
        try {
            $mRutasManejador = new RutasManejador();
            $rutasRemesasMontosEnterosDto = new RutasRemesasMontosEnterosDto();


            $rutasRemesasMontosEnterosDto->setIdRutasRemesa($rutaRemesa);
            $rutasRemesasMontosEnterosDto->setIdTipoEntero($idTipoEntero);
            $rutasRemesasMontosEnterosDto->setCantidad($cantidad);

            $mRutasManejador->crearRutasRemesasMontosEnteros($rutasRemesasMontosEnterosDto);
        } catch (Exception $e) {
            return 'Error en: ' . __METHOD__ . ' ' . $e->getMessage();
        }
    }

}

