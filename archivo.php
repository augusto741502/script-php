<?php

namespace App\Controller;

use App\_dto\entidades\dominio\ObjetoValoradoDto;
use App\_dto\entidades\dominio\RemesaComposicionDto;
use App\_dto\entidades\preparacion\BovedaResumenDetalleDto;
use App\_dto\entidades\preparacion\BovedaTransaccionDetalleDto;
use App\_dto\entidades\preparacion\BovedaTransaccionDto;
use App\_dto\entidades\preparacion\TraspasoSupervisorACajeroDetalleDto;
use App\_dto\entidades\preparacion\TraspasoSupervisorACajeroDto;
use App\_dto\entidades\recuento\ProcesoBultoDto;
use App\_dto\entidades\recuento\ProcesoRemitoDto;
use App\_dto\entidades\recuento\ProcesoRemesaConfeccionSupervisorDto;

use App\_manejador\AccesoManejador;
use App\_manejador\BovedaManejador;
use App\_manejador\ClienteManejador;
use App\_manejador\ObjetosValoradosManejador;
use App\_manejador\PreparacionManejador;
use App\_manejador\RecuentoManejador;
use App\_manejador\SalaProcesoManejador;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Exception;
use stdClass;

use App\_servicios\Io;

class SupervisorController extends AbstractController
{
    private $io;

    public function __construct()
    {
        $this->io = new Io();
    }

    #[Route('/supervisor')]
    public function index(): Response
    {
        try {
            return $this->render('Supervisor/SupervisorVista.html.twig');
        } catch (Exception $e) {
            return 'Error renderizando la vista' . $e->getMessage();
        }
    }

    public function convertirTipoMoneda($tipoMoneda, $valor, $puntoMiles = true)
    {
        if (strtoupper($tipoMoneda) == "ARG") {
            return number_format((float)str_replace(".", ",", $valor), 0, ",", ($puntoMiles) ? "." : "");
        } else {
            return $this->convertirTipoMonedaNoCLP($valor, $puntoMiles);
        }
    }
    private function convertirTipoMonedaNoCLP($valor, $puntoMiles = true)
    {
        $separador = explode(",", $valor);
        if (array_key_exists('1', $separador)) {
            return number_format($separador[0], 0, ",", ($puntoMiles) ? "." : "") . (($separador[1] == "") ? "" : ",") . $separador[1];
        } else {
            return number_format($separador[0], 2, ",", ($puntoMiles) ? "." : "");
        }
    }

    private function montoSupervisorActual($id, $valor, $suma = false, $resta = false)
    {
        $mPreparacionManejador  = new PreparacionManejador();
        $MontoSupervisorActualCajeroDetalleDto = $mPreparacionManejador->recuperarMontoSupervisorActualCajeroDetalleOp($id);
        if ($suma == true) {
            $MontoSupervisorActualCajeroDetalleDto->setMontoDisponible($MontoSupervisorActualCajeroDetalleDto->getMontoDisponible() + $valor);
        } else if ($resta == true) {
            $MontoSupervisorActualCajeroDetalleDto->setMontoDisponible($MontoSupervisorActualCajeroDetalleDto->getMontoDisponible() - $valor);
        }
        $mPreparacionManejador->modificarMontoSupervisorActualCajeroDetalleOp($MontoSupervisorActualCajeroDetalleDto);
    }

    private function descontarMontoSupervisorTransferidoDesdeBoveda($id_proceso_supervisor, $idCustodia, $ListaMontosTraspaso)
    {
        $mPreparacionManejador             = new PreparacionManejador();

        $DetalleMontoActualTraspasosBovedaDto = $mPreparacionManejador->recuperarMontoActualSupervisorTraspasoBoveda($id_proceso_supervisor, $idCustodia);

        foreach ($ListaMontosTraspaso as $MontosTraspaso) {
            $montoDigitado = $MontosTraspaso["monto"];
            foreach ($DetalleMontoActualTraspasosBovedaDto->getListaMontos() as $MontoSupervisorActualBovedaDetalle) {
                if ($MontoSupervisorActualBovedaDetalle->getDenominacion() == $MontosTraspaso["denominacion"] & $MontoSupervisorActualBovedaDetalle->getComposicion() == $MontosTraspaso["composicion"]) {
                    $disponible = $MontoSupervisorActualBovedaDetalle->getMontoDisponible();
                    if ($disponible > 0) {
                        $total = $disponible - $montoDigitado;
                        if ($total < 0) {
                            $MontoSupervisorActualBovedaDetalleDto = $mPreparacionManejador->recuperarMontoSupervisorActualBovedaDetalleOp($MontoSupervisorActualBovedaDetalle->getId());
                            $MontoSupervisorActualBovedaDetalleDto->setMontoDisponible(0);
                            $mPreparacionManejador->modificarMontoSupervisorActualBovedaDetalleOp($MontoSupervisorActualBovedaDetalleDto);
                            $montoDigitado = $total * -1;
                        } else {
                            $MontoSupervisorActualBovedaDetalleDto = $mPreparacionManejador->recuperarMontoSupervisorActualBovedaDetalleOp($MontoSupervisorActualBovedaDetalle->getId());
                            $MontoSupervisorActualBovedaDetalleDto->setMontoDisponible($total);
                            $mPreparacionManejador->modificarMontoSupervisorActualBovedaDetalleOp($MontoSupervisorActualBovedaDetalleDto);
                            break;
                        }
                    }
                }
            }
        }
    }

    private function descontarMontoSupervisorTransferidoDesdeCajero($id_proceso_supervisor, $idCustodia, $ListaMontosTraspaso)
    {
        $mPreparacionManejador                  = new PreparacionManejador();
        $MontoSupervisorActualCajeroDetalle     = null;
        $DetalleMontoActualTraspasosCajeroDto = $mPreparacionManejador->recuperarMontoSupervisorTraspasosCajeros($id_proceso_supervisor, $idCustodia);
        foreach ($ListaMontosTraspaso as $MontosTraspaso) {
            foreach ($DetalleMontoActualTraspasosCajeroDto->getListaMontos() as $MontoSupervisorActualCajeroDetalleDto) {
                if ($MontoSupervisorActualCajeroDetalleDto->getDenominacion()->getId() == $MontosTraspaso["denominacion"] & $MontoSupervisorActualCajeroDetalleDto->getComposicion()->getId() == $MontosTraspaso["composicion"]) {
                    $MontoSupervisorActualCajeroDetalle = $mPreparacionManejador->recuperarMontoSupervisorActualCajeroDetalleOp($MontoSupervisorActualCajeroDetalleDto->getId());
                    $disponible = $MontoSupervisorActualCajeroDetalle->getMontoDisponible();
                    $total      = $disponible - $MontosTraspaso["monto"];
                    $total      = ($total < 0) ? 0 : $total;
                    $MontoSupervisorActualCajeroDetalle->setMontoDisponible($total);
                    $mPreparacionManejador->modificarMontoSupervisorActualCajeroDetalleOp($MontoSupervisorActualCajeroDetalle);
                    break;
                }
            }
        }
    }

    #[Route('/api/supervisor/recuperar-total-remitos-bultos', name: 'recuperar-total-remitos-bultos', methods: 'GET')]
    public function totalremitospendientesprocesados(): JsonResponse
    {
        try {
            $mRecuentoManejador           = new RecuentoManejador();
            $mObjetosValoradosManejador   = new ObjetosValoradosManejador();
            $mSalaProcesoManejador        = new SalaProcesoManejador();
            $sesion_global               = $mSalaProcesoManejador->recuperarSesionGlobalActiva();

            $arrayProcesoRemitoEsperaDto = $mRecuentoManejador->recuperarRemitosEnEspera(null, null, null, $sesion_global->getId());

            $hay_pendientes = (count($arrayProcesoRemitoEsperaDto) > 0) ? true : false;

            //total remito
            $contador_remitos_proceso   = 0;
            $contador_remitos_sala      = 0;
            $contador_bultos_sala       = 0;
            $contador_bultos_procesados = 0;

            $arrayDetalleProcesoRemitoDto = $mRecuentoManejador->ListaProcesoRemitoFiltros(null, null, null, null, null, null, null, null, null, null, $sesion_global->getId(), null);
            if (count($arrayDetalleProcesoRemitoDto) > 0) {

                foreach ($arrayDetalleProcesoRemitoDto as $DetalleProcesoRemito) {

                    $DetalleRemitoDto         = $mObjetosValoradosManejador->recuperarDetalleRemito(null, $DetalleProcesoRemito->getIdObjetoValorado());
                    $DetalleRecuentoRemitoDto = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $DetalleProcesoRemito->getIdObjetoValorado());
                    $DetalleProcesoRemitoDtoEspecifico = $mRecuentoManejador->recuperarDetalleProcesoRemito(null, null, $DetalleProcesoRemito->getIdObjetoValorado());

                    if ($DetalleProcesoRemitoDtoEspecifico->getEstadoProceso()->getId() <= 2) {
                        $contador_remitos_sala++;
                    } elseif ($DetalleProcesoRemitoDtoEspecifico->getEstadoProceso()->getId()  >= 3 && $DetalleProcesoRemito->getEstadoProceso()->getId()  <= 7) {
                        $contador_remitos_proceso++;
                    }

                    $arrayIdObjetos           = array();

                    foreach ($DetalleRecuentoRemitoDto->getListaProcesos() as $DetalleRecuentoBultoDto) {
                        array_push($arrayIdObjetos, $DetalleRecuentoBultoDto->getIdObjetoValorado());
                    }

                    foreach ($DetalleRemitoDto->getListaBultos() as $ObjetoValoradoDto) {
                        if ($DetalleProcesoRemitoDtoEspecifico->getEstadoProceso()->getId() <= 2) {
                            if (!in_array($ObjetoValoradoDto->getId(), $arrayIdObjetos)) {
                                $contador_bultos_sala = $contador_bultos_sala + 1;
                            }
                        } elseif ($DetalleProcesoRemitoDtoEspecifico->getEstadoProceso()->getId()  >= 3 && $DetalleProcesoRemito->getEstadoProceso()->getId()  <= 7) {
                            $contador_bultos_procesados = $contador_bultos_procesados + 1;
                        }
                    }
                }
            }

            $resultadoJson = array(
                'remito_espera' => $contador_remitos_sala,
                'bulto_espera' => $contador_bultos_sala,
                'remito_proceso' => $contador_remitos_proceso,
                'bulto_proceso' => $contador_bultos_procesados
            );

            $this->io->setDatos(['hay_pendientes' => $hay_pendientes, "totalRemitos" => $resultadoJson]);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }
        return $this->json($this->io);
    }

    #[Route('/api/supervisor/asigna-remitos-pendiente-session-global/{idProcesoSupervisor}', name: 'asigna-remitos-pendiente-session-global', methods: 'GET')]
    public function asignaRemitoSessionActual(Request $request, $idProcesoSupervisor): JsonResponse
    {
        try {
            $mSalaProcesoManejador  = new SalaProcesoManejador();
            $mRecuentoManejador     = new RecuentoManejador();
            $sesion_global = $mSalaProcesoManejador->recuperarSesionGlobalActiva();

            $arrayProcesoRemitoEsperaTem = $mRecuentoManejador->recuperarRemitosEnEspera(null, null, null, $sesion_global->getId());
            $arrayProcesoRemitoEsperaDto = array();
            $fecha = date("Y-m-d H:i:s");
            $fechaDate = date("Y-m-d");
            $bool = false;
            foreach ($arrayProcesoRemitoEsperaTem as $ProcesoRemitoEsperaDto) {
                if ($ProcesoRemitoEsperaDto->getIdLugarFisico() == 4 & $ProcesoRemitoEsperaDto->getConfirmado() == 0) {
                    array_push($arrayProcesoRemitoEsperaDto, $ProcesoRemitoEsperaDto);
                }
            }

            if (count($arrayProcesoRemitoEsperaDto) > 0) {
                foreach ($arrayProcesoRemitoEsperaDto as $ProcesoRemitoEsperaDto) {
                    if ($ProcesoRemitoEsperaDto->getObjetoValorado() != null) {
                       
                        $ProcesoRemitoDto = null;
                        $DetalleProcesoRemitoDto = $mRecuentoManejador->recuperarDetalleProcesoRemito(null, null, $ProcesoRemitoEsperaDto->getObjetoValorado());
                        if ($DetalleProcesoRemitoDto->getIdProcesoRemito() != null) {
                            $ProcesoRemitoDto = $mRecuentoManejador->recuperarProcesoRemitoOP($DetalleProcesoRemitoDto->getIdProcesoRemito());
                        }

                        if ($ProcesoRemitoDto == null) {
                            $ProcesoRemitoDto = new ProcesoRemitoDto();
                            $ProcesoRemitoDto->setObjetoValorado($ProcesoRemitoEsperaDto->getObjetoValorado());
                            $ProcesoRemitoDto->setEstadoProceso(1);
                            $ProcesoRemitoDto->setProcesoSupervisor($idProcesoSupervisor);
                            $ProcesoRemitoDto->setFechaProceso($fechaDate);
                            $ProcesoRemitoDto->setSesionGlobal($sesion_global->getId());
                            $mRecuentoManejador->insertarProcesoRemito($ProcesoRemitoDto);
                        } else {
                            $ProcesoRemitoDto->setObjetoValorado($ProcesoRemitoEsperaDto->getObjetoValorado());
                            $ProcesoRemitoDto->setEstadoProceso(1);
                            $ProcesoRemitoDto->setProcesoSupervisor($idProcesoSupervisor);
                            $ProcesoRemitoDto->setFechaProceso($fechaDate);
                            $ProcesoRemitoDto->setSesionGlobal($sesion_global->getId());
                            $mRecuentoManejador->modificarProcesoRemito($ProcesoRemitoDto);
                        }

                        $ProcesoRemitoEsperaDto = $mRecuentoManejador->recuperarRemitoEnEspera($ProcesoRemitoEsperaDto->getId());
                        if ($ProcesoRemitoEsperaDto->getId() != null) {
                            $ProcesoRemitoEsperaDto->setConfirmado(1);
                            $ProcesoRemitoEsperaDto->setFechaConfirmacion($fecha);
                            $bool = $mRecuentoManejador->modificarRemitoEspera($ProcesoRemitoEsperaDto);
                        }
                    }
                }
            }

            $this->io->setDatos(['asignadosProcesoRemitoEspera' => $bool]);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }
        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-remitos-estado/{id_estado}', name: 'obtenerRemitosSessionGlobal', methods: 'GET')]
    public function obtieneremitossessionglobal(Request $request, $id_estado): JsonResponse
    {
        try {
            #PENDIENTE = 1
            #CONFIRMADO = 2
            #ASIGNADO = 4
            #PROCESADO = 7
            #EN EL CASO 3 SIGNIFICA QUE LISTA TODOS LOS REMITOS INDEPENDIENTE DEL ESTADO
            $mRecuentoManejador         = new RecuentoManejador();
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();
            $mSalaProcesoManejador      = new SalaProcesoManejador();
            $sesion_global              = $mSalaProcesoManejador->recuperarSesionGlobalActiva();

            #DECLARAMOS UN ARRAY QUE CONTENDRA LOS ESTADO A BUSCAR 
            $EstadoArray = array();
            #APLICAMOS UN SWITCH PARA DECLARAR LOS ESTADOS 
            #CASO 3 TIENE TODOS LOS ESTADOS
            switch ($id_estado) {
                case 1:
                    $EstadoArray = array(1);
                    break;
                case 2:
                case 4:
                case 5:
                case 6:
                    $EstadoArray = array(2, 3, 4, 5, 6);
                    break;
                    /*case 4:
                $EstadoArray = array(4,5,6);
            break;*/
                case 7:
                    $EstadoArray = array(7);
                    break;
                case 3:
                    $EstadoArray = array(1, 2, 4, 5, 6, 7, 3);
                    break;
                default:
                    break;
            }

            $arrayDetalleProcesoRemitoDto = $mRecuentoManejador->ListaProcesoRemitoFiltros(null, null, null, null, null, null, null, null, null, null, $sesion_global->getId(), $EstadoArray);

            $resultadoJson = [];

            if (count($arrayDetalleProcesoRemitoDto) > 0) {
                $contador_bultos_final = 0;

                foreach ($arrayDetalleProcesoRemitoDto as $DetalleProcesoRemitoDto) {
                    $DetalleRemitoDto = $mObjetosValoradosManejador->recuperarDetalleRemito(null, $DetalleProcesoRemitoDto->getIdObjetoValorado());
                    array_push($resultadoJson, array(
                        'detalleProcesoRemito' => $DetalleProcesoRemitoDto,
                        'detalleRemito' => $DetalleRemitoDto
                    ));
                }
            }
            $this->io->setDatos($resultadoJson);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }
        return $this->json($this->io);
    }

    #[Route('/api/supervisor/buscar-remito/{remito}', name: 'buscar-remito', methods: 'GET')]
    public function buscaRemito(Request $request, $remito): JsonResponse
    {
        try {
            $mClienteManejador          = new ClienteManejador();
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();
            $mRecuentoManejador         = new RecuentoManejador();

            $contador_bultos_final = 0;

            $resultadoJson = [];
            $listaremitos           = $mObjetosValoradosManejador->recuperaraRemitosPorTexto($remito);
            foreach ($listaremitos as $remito) {

                $DetalleRemitoDto           = $mObjetosValoradosManejador->recuperarDetalleRemito($remito->getCodigoObjeto());
                $DetalleRecuentoRemitoDto   = $mRecuentoManejador->recuperarDetalleRecuentoRemito($remito->getCodigoObjeto());
                $ProcesoRemitoDto           = $mRecuentoManejador->recuperarProcesoRemitoOP($DetalleRecuentoRemitoDto->getIdProcesoRemito());
                $ClienteDto                 = $mClienteManejador->recuperarClientesOP($DetalleRemitoDto->getPuntoRetiro()->getCliente());

                if ($DetalleRemitoDto != null) {

                    $idObjetoValorado = $DetalleRemitoDto->getIdObjetoValorado();
                    $estadoProceso = $ProcesoRemitoDto->getEstadoProceso();
                    $CodigoRemito = $DetalleRemitoDto->getCodigoRemito();
                    $nombreCliente = $ClienteDto->getNombreCliente();
                    $direccionPunto = $DetalleRemitoDto->getPuntoRetiro()->getDireccionPunto();

                    $contador_bultos = count($DetalleRemitoDto->getListaBultos());
                    $contador_bultos_final = $contador_bultos_final + $contador_bultos;

                    array_push(
                        $resultadoJson,
                        array(
                            'idObjetoValorado' => $idObjetoValorado,
                            'estadoProceso' => $estadoProceso,
                            'contador_bultos' => $contador_bultos,
                            'contador_bultos_final' => $contador_bultos_final,
                            'CodigoRemito' => $CodigoRemito,
                            'nombreCliente' => $nombreCliente,
                            'DireccionPunto' => $direccionPunto
                        )
                    );
                }
            }

            $this->io->setDatos($resultadoJson);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-lista-procesos-asignacion-cajeros/{id_usuario}', name: 'recuperarListaProcesosAsignacionCajeros', methods: 'GET')]
    public function listarCajeros(Request $request, $id_usuario): JsonResponse
    {
        try {
            $mSalaProcesoManejador = new SalaProcesoManejador();
            $mRecuentoManejador    = new RecuentoManejador();
            $resultadoJson = [];

            $arrayDetalleCajeroDto  = $mSalaProcesoManejador->recuperarCajeros(1);
         
            usort($arrayDetalleCajeroDto, function ($a, $b) {
                return [$a->getUsuario()->getNombrePersona(), $a->getUsuario()->getNombrePersona()] <=> [$b->getUsuario()->getNombrePersona(), $b->getUsuario()->getNombrePersona()];
            });

            if (count($arrayDetalleCajeroDto) > 0) {
                foreach ($arrayDetalleCajeroDto as $DetalleCajeroDto) {
   
                    if ($DetalleCajeroDto->getModuloTrabajo() != 0) {
                        if ($id_usuario == null || $id_usuario == "null") { //todos los cajeros

                            $contador_total_procesos = $mRecuentoManejador->recuperarCantidadDeProcesosPendientesPorCajero($DetalleCajeroDto->getIdProcesoCajero());
                            $contador_total_asignado =  $mRecuentoManejador->recuperarCantidadDeProcesosAsignadosPorCajero($DetalleCajeroDto->getIdProcesoCajero());

                            array_push($resultadoJson, array(
                                'contador_total_procesos' => $contador_total_procesos,
                                'contador_total_asignado' => $contador_total_asignado,
                                'detalleCajero' => $DetalleCajeroDto
                            ));
                        } else {
                            /* cajero especifico */
                            if ($id_usuario == $DetalleCajeroDto->getUsuario()->getId()) {
                                $contador_total_procesos = $mRecuentoManejador->recuperarCantidadDeProcesosPendientesPorCajero($DetalleCajeroDto->getIdProcesoCajero());
                                $contador_total_asignado =  $mRecuentoManejador->recuperarCantidadDeProcesosAsignadosPorCajero($DetalleCajeroDto->getIdProcesoCajero());

                                array_push($resultadoJson, array(
                                    'contador_total_procesos' => $contador_total_procesos,
                                    'contador_total_asignado' => $contador_total_asignado,
                                    'detalleCajero' => $DetalleCajeroDto
                                ));
                            }
                        }
                    }
                }
            }
            $this->io->setDatos($resultadoJson);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-cajeros-activos', name: 'recuperarCajerosActivosSuper', methods: 'GET')]
    public function listarCajerosActivos(): JsonResponse
    {
        try {
            $mSalaProcesoManejador = new SalaProcesoManejador();
            $arrayDetalleCajeroDto = $mSalaProcesoManejador->recuperarCajeros(1);
            $resultadoJsonLista = [];
            if (count($arrayDetalleCajeroDto) > 0) {
                foreach ($arrayDetalleCajeroDto as $Cajeros) {
                    if ($Cajeros->getModuloTrabajo() != 0) {
                        array_push($resultadoJsonLista, $Cajeros);
                    }
                }
            }

            $this->io->setDatos($resultadoJsonLista);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/asigna-envio-aduana/{idObjetoValorado}', name: 'asignarEnvioAduana', methods: 'PUT')]
    public function enviaAduana(Request $request, $idObjetoValorado): JsonResponse
    {
        try {
            $mRecuentoManejador           = new RecuentoManejador();
            $mObjetosValoradosManejador   = new ObjetosValoradosManejador();
            $objetoValoradoDto  = null;
            $DetalleRecuentoRemitoDto = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $idObjetoValorado);
            if ($DetalleRecuentoRemitoDto->getIdObjetoValorado() != null) {
                $IdProcesoBulto   = null;
                $IdProcesoRemito  = $DetalleRecuentoRemitoDto->getIdProcesoRemito();
                $id_Remitoestado  = $DetalleRecuentoRemitoDto->getEstadoProceso()->getId();
                $ValidoCambiar    = true;
                if (count($DetalleRecuentoRemitoDto->getListaProcesos()) > 0) {
                    $IdProcesoBulto = $DetalleRecuentoRemitoDto->getListaProcesos()[0]->getIdProcesoBulto();
                }

                if ($id_Remitoestado == 3) {
                    foreach ($DetalleRecuentoRemitoDto->getListaProcesos() as $DetalleRecuentoBultoDto) {
                        if ($DetalleRecuentoBultoDto->getEstadoProceso()->getId() != 1) {
                            $ValidoCambiar = false;
                        }
                    }
                } else if ($id_Remitoestado == 1 | $id_Remitoestado == 2) {
                    $ValidoCambiar = true;
                } else {
                    $ValidoCambiar = false;
                }

                if ($ValidoCambiar) {
                    //modifica proceso y deposito
                    if ($IdProcesoBulto != null) {
                        $mRecuentoManejador->eliminarProcesoBulto($IdProcesoBulto);
                    }
                    //Eliminar proceso_remito
                    if ($IdProcesoRemito > 0) {
                        $ProcesoRemitoDto = $mRecuentoManejador->recuperarProcesoRemitoOP($IdProcesoRemito);
                        $mRecuentoManejador->eliminarProcesoRemito($ProcesoRemitoDto);
                    }
                    //Eliminar proceso_remito_espera
                    $procesoRemitoEspera = $mRecuentoManejador->recuperarRemitoEnEspera(null, $idObjetoValorado);
                    if ($procesoRemitoEspera->getId() != null) {
                        $mRecuentoManejador->eliminarRemitoEspera($procesoRemitoEspera->getId());
                    }
                    //Modificar objetos_valorados
                    $objetoValoradoDto = $mObjetosValoradosManejador->recuperarObjetosValoradoOP($idObjetoValorado);
                    $objetoValoradoDto->setIdEstado(2);
                    $ValidoCambiar = $mObjetosValoradosManejador->modificarObjetoValorado($objetoValoradoDto);
                }
            }

            $this->io->setDatos(["cambiado" => $ValidoCambiar, "objetoValorado" => $objetoValoradoDto]);
            
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/asigna-todo-aduana', name: 'asignarTodoAduana', methods: 'GET')]
    public function enviarAduanaTodas(Request $request): JsonResponse
    {
        try {
            $mRecuentoManejador             = new RecuentoManejador();
            $mObjetosValoradosManejador     = new ObjetosValoradosManejador();
            $mSalaProcesoManejador          = new SalaProcesoManejador();
            $sesion_global                  = $mSalaProcesoManejador->recuperarSesionGlobalActiva();
            $arrayObjetoValorado = [];
            $arrayDetalleProcesoRemitoDto   = $mRecuentoManejador->recuperarListaDetalleProcesoRemito($sesion_global->getId(), null);
            if (count($arrayDetalleProcesoRemitoDto) > 0) {
                foreach ($arrayDetalleProcesoRemitoDto as $DetalleProcesoRemitoDto) {
                    $id_Remitoestado = $DetalleProcesoRemitoDto->getEstadoProceso()->getId();
                    $DetalleRecuentoRemitoDto = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $DetalleProcesoRemitoDto->getIdObjetoValorado());
                    if ($DetalleProcesoRemitoDto->getIdObjetoValorado() != null) {
                        $IdProcesoRemito  = $DetalleProcesoRemitoDto->getIdProcesoRemito();
                        $ValidoCambiar    = true;
                        if ($id_Remitoestado == 1) //Pendiente
                        {
                            $ValidoCambiar    = true;
                        } else if ($id_Remitoestado == 2) //Confirmados
                        {
                            $ValidoCambiar    = true;
                        } else if ($id_Remitoestado == 3) //Asignados y cajero no resibido
                        {
                            foreach ($DetalleRecuentoRemitoDto->getListaProcesos() as $DetalleRecuentoBultoDto) {
                                if ($DetalleRecuentoBultoDto->getEstadoProceso()->getId() != 1) {
                                    $ValidoCambiar = false;
                                }
                            }
                        } else { //Otro
                            $ValidoCambiar = false;
                        }

                        if ($ValidoCambiar) {

                            $cantidadBultos = count($DetalleRecuentoRemitoDto->getListaProcesos());
                            if (count($DetalleRecuentoRemitoDto->getListaProcesos()) > 0) {
                                foreach ($DetalleRecuentoRemitoDto->getListaProcesos() as $DetalleRecuentoBultoDto) {
                                    $mRecuentoManejador->eliminarProcesoBulto($DetalleRecuentoBultoDto->getIdProcesoBulto());
                                }
                            }

                            //Eliminar proceso_remito
                            if ($IdProcesoRemito > 0) {
                                $ProcesoRemitoDto = $mRecuentoManejador->recuperarProcesoRemitoOP($IdProcesoRemito);
                                $mRecuentoManejador->eliminarProcesoRemito($ProcesoRemitoDto);
                            }
                            //Eliminar proceso_remito_espera
                            $procesoRemitoEspera = $mRecuentoManejador->recuperarRemitoEnEspera(null, $DetalleProcesoRemitoDto->getIdObjetoValorado());

                            if ($procesoRemitoEspera->getId() != null) {
                                $mRecuentoManejador->eliminarRemitoEspera($procesoRemitoEspera->getId());
                            }
                            //Modificar objetos_valorados
                            $objetoValoradoDto = $mObjetosValoradosManejador->recuperarObjetosValoradoOP($DetalleProcesoRemitoDto->getIdObjetoValorado());
                            $objetoValoradoDto->setIdEstado(2);
                            $bool = $mObjetosValoradosManejador->modificarObjetoValorado($objetoValoradoDto);

                            if ($bool) {
                                array_push($arrayObjetoValorado, array("objetoValorado" => $objetoValoradoDto));
                            }
                        }
                    }
                }
            }
            $this->io->setDatos(["cambiado" => ((count($arrayObjetoValorado) > 0) ? true : false), "listaObjetoValorado" => $arrayObjetoValorado]);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }
        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-procesos-cajero/{id_proceso_cajero}/{idUsuario}/', name: 'recuperarProcesosCajero', methods: 'GET')]
    public function verProcesosCajero(Request $request, $id_proceso_cajero, $idUsuario): JsonResponse
    {
        try {
            $mRecuentoManejador           = new RecuentoManejador();
            $mObjetosValoradosManejador   = new ObjetosValoradosManejador();
            $mClienteManejador            = new ClienteManejador();
            $mSalaProcesoManejador        = new SalaProcesoManejador();
            $mAccesoManejador             = new AccesoManejador();

            $contador_traspaso            = 0;
            $resultadoJsonRemitos = [];

            $arrayDetalleRecuentoRemitoDto  = $mRecuentoManejador->recuperarRecuentoPorCajero($id_proceso_cajero);
            $ProcesoCajeroDto               = $mSalaProcesoManejador->recuperarProcesoCajeroOP($id_proceso_cajero);
            $Cajeros                        = $mAccesoManejador->recuperarUsuarioOp($ProcesoCajeroDto->getUsuario());
            
            if (count($arrayDetalleRecuentoRemitoDto) > 0) {

                foreach ($arrayDetalleRecuentoRemitoDto as $keyD => $DetalleRecuentoRemitoDto) {

                    $objetoValoradoDto          = $mObjetosValoradosManejador->recuperarObjetosValoradoOP($DetalleRecuentoRemitoDto->getIdObjetoValorado());
                    if ($objetoValoradoDto->getPuntoRetiro() != null) {
                        $ClienteDto = 0;
                        $resultadoJsonLista = [];
                        $PuntoDto = $mClienteManejador->recuperarPuntoOP($objetoValoradoDto->getPuntoRetiro());
                        $VClienteDto = null;
                        $DireccionPunto = null;
                        $NombrePersona = null;
                        $ModuloTrabajo = null;

                        if ($PuntoDto != null) {
                            $ClienteDto = $mClienteManejador->recuperarClientesOP($PuntoDto->getCliente());
                            if ($ClienteDto != null) {
                                $VClienteDto = $ClienteDto->getNombreCliente();
                                $DireccionPunto = $PuntoDto->getDireccionPunto();
                                $NombrePersona = $Cajeros->getNombrePersona();
                                $ModuloTrabajo = $ProcesoCajeroDto->getModuloTrabajo();
                            }
                        }

                        $DetalleRecuentoRemitoDto = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, null, $DetalleRecuentoRemitoDto->getIdProcesoRemito());
                        if ($DetalleRecuentoRemitoDto->getIdObjetoValorado() != null) {
                            foreach ($DetalleRecuentoRemitoDto->getListaProcesos() as $keyL => $DetalleRecuentoBultoDto) {
                                if ($DetalleRecuentoBultoDto->getCajero()->getId() == $idUsuario) {
                                    $IdProcesoBulto = null;
                                    $contador_depositos = count($DetalleRecuentoBultoDto->getListaDepositos());
                                    if ($DetalleRecuentoBultoDto->getEstadoProceso()->getId() == 1) {
                                        $contador_traspaso++;
                                        $IdProcesoBulto = $DetalleRecuentoBultoDto->getIdProcesoBulto();
                                    }

                                    $resultadoJsonLista[$keyL] = array(
                                        'IdProcesoRemito' => $DetalleRecuentoBultoDto->getIdProcesoRemito(),
                                        'CodigoPrecinto' => $DetalleRecuentoBultoDto->getCodigoPrecinto(),
                                        'contador_depositos' => $contador_depositos,
                                        'contador_traspaso' => $contador_traspaso,
                                        'IdProcesoBulto' => $IdProcesoBulto,
                                        'ClienteDto' => $VClienteDto
                                    );
                                }
                            }
                        }

                        $resultadoJsonRemitos[$keyD] = array(
                            'DireccionPunto' => $DireccionPunto,
                            'NombrePersona' => $NombrePersona,
                            'CodigoRemito' => $DetalleRecuentoRemitoDto->getCodigoRemito(),
                            'ModuloTrabajo' => $ModuloTrabajo,
                            'IdProcesoRemito' => $DetalleRecuentoRemitoDto->getIdProcesoRemito(),
                            'ListaProcesos' => $resultadoJsonLista
                        );
                    }
                }
            }

            $this->io->setDatos([
                    'contador_traspaso' => $contador_traspaso,
                    'listaRemitos' => $resultadoJsonRemitos
                ]);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-procesos-remitos/{id_remito_ver_proceso}', name: 'recuperarProcesosRemitos', methods: 'GET')]
    public function verProcesosRemito(Request $request, $id_remito_ver_proceso): JsonResponse
    {
        try {
            $mRecuentoManejador         = new RecuentoManejador();
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();
            $mClienteManejador          = new ClienteManejador();

            $resultadoJson = [];

            $DetalleRecuentoRemitoDto = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $id_remito_ver_proceso);

            if ($DetalleRecuentoRemitoDto->getIdObjetoValorado() != null) {
                $objetoValoradoDto = $mObjetosValoradosManejador->recuperarObjetosValoradoOP($DetalleRecuentoRemitoDto->getIdObjetoValorado());
                if ($objetoValoradoDto->getPuntoRetiro() != null) {
                    $banco_dependencia = null;
                    $color = null;
                    $NombreCliente = null;
                    $DireccionPunto = null;
                    try {
                        $PuntoDto   = $mClienteManejador->recuperarPuntoOP($objetoValoradoDto->getPuntoRetiro());
                        $ClienteDto = null;
                        if ($PuntoDto != null) {
                            $ClienteDto = $mClienteManejador->recuperarClientesOP($PuntoDto->getCliente());
                        }

                        $resultadoJsonProcesos = [];
                        if (count($DetalleRecuentoRemitoDto->getListaProcesos()) > 0) {
                            foreach ($DetalleRecuentoRemitoDto->getListaProcesos() as $DetalleRecuentoRemitoDto) {
                                $contador_depositos = count($DetalleRecuentoRemitoDto->getListaDepositos());
                                array_push($resultadoJsonProcesos, array(
                                    'contador_depositos' => $contador_depositos,
                                    'IdProcesoRemito' => $DetalleRecuentoRemitoDto->getIdProcesoRemito(),
                                    'CodigoPrecinto' => $DetalleRecuentoRemitoDto->getCodigoPrecinto(),
                                    'IdProcesoBulto' => $DetalleRecuentoRemitoDto->getIdProcesoBulto(),
                                    'CodigoPrecinto' => $DetalleRecuentoRemitoDto->getCodigoPrecinto()
                                ));
                            }
                        }

                        $resultadoJson = [
                            'banco_dependencia' => $banco_dependencia,
                            'color' => $color,
                            'CodigoRemito' => $DetalleRecuentoRemitoDto->getCodigoRemito(),
                            'NombreCliente' => $NombreCliente,
                            'DireccionPunto' => $DireccionPunto,
                            'IdProcesoRemito' => $DetalleRecuentoRemitoDto->getIdProcesoRemito(),
                            'listaProcesos' => $resultadoJsonProcesos,
                            'cliente' => $ClienteDto,
                            'punto' => $PuntoDto

                        ];
                    } catch (Exception $e) {
                    }
                }
            }

            $this->io->setDatos($resultadoJson);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recupera-detalle-proceso/{idCodigoPrecinto}', name: 'recuperarDetalleProceso', methods: 'GET')]
    public function verDetalleProceso(Request $request, $idCodigoPrecinto): JsonResponse
    {
        try {
            $mRecuentoManejador     = new RecuentoManejador();
            $DetalleRecuentoBultoDto  = $mRecuentoManejador->recuperarDetalleRecuentoBulto($idCodigoPrecinto);

            $resultadoJson = [];

            if ($DetalleRecuentoBultoDto->getIdObjetoValorado() != null) {

                $monto_declarado_lapso  = 0;
                $monto_procesado_lapso  = 0;
                $monto_diferencia_lapso = 0;
                $monto_total_procesado  = 0;
                $monto_declarado_final  = 0;
                $monto_procesado_final  = 0;
                $monto_diferencia_final = 0;
                $monto_declarado_cheque = 0;
                $monto_declarado_efectivo = 0;
                $NumeroTransaccion = 0;
                $MontoDeclarado = 0;
                $MontoProcesado = 0;
                $MontoDiferencia = 0;

                $resultadoJsonLista = [];
                $simbologia = "$";
                $tipoMoneda = "ARG";

                if (count($DetalleRecuentoBultoDto->getListaDepositos()) > 0) {

                    $diferenciaContenedor =  " diferencia_contenedor";
                    foreach ($DetalleRecuentoBultoDto->getListaDepositos() as $keyB => $DetalleDepositoBultoDto) {

                        if ($DetalleDepositoBultoDto->getMontoProcesadoCLP() > 0) {
                            $simbologia = "$";
                            $tipoMoneda = "CLP";
                            $monto_declarado_lapso  = $monto_declarado_lapso  + $DetalleDepositoBultoDto->getMontoDeclaradoCLP();
                            $monto_procesado_lapso  = $monto_procesado_lapso  + $DetalleDepositoBultoDto->getMontoProcesadoCLP();
                            $monto_diferencia_lapso = $monto_diferencia_lapso + $DetalleDepositoBultoDto->getMontoDiferenciaCLP();
                            $monto_declarado_final  = $monto_declarado_final  + $DetalleDepositoBultoDto->getMontoDeclaradoCLP();
                            $monto_procesado_final  = $monto_procesado_final  + $DetalleDepositoBultoDto->getMontoProcesadoCLP();
                            $monto_diferencia_final = $monto_diferencia_final + $DetalleDepositoBultoDto->getMontoDiferenciaCLP();
                            $NumeroTransaccion      = $DetalleDepositoBultoDto->getNumeroTransaccion();
                            $MontoDeclarado         = $DetalleDepositoBultoDto->getMontoDeclaradoCLP();
                            $MontoProcesado         = $DetalleDepositoBultoDto->getMontoProcesadoCLP();
                            $MontoDiferencia        = $DetalleDepositoBultoDto->getMontoDiferenciaCLP();
                            $clase_diferencia       = ($DetalleDepositoBultoDto->getMontoDiferenciaCLP() != 0) ? $diferenciaContenedor  : "";
                        } elseif ($DetalleDepositoBultoDto->getMontoProcesadoUSD() > 0) {
                            $simbologia = "$";
                            $tipoMoneda = "USD";
                            $monto_declarado_lapso  = $monto_declarado_lapso  + $DetalleDepositoBultoDto->getMontoDeclaradoUSD();
                            $monto_procesado_lapso  = $monto_procesado_lapso  + $DetalleDepositoBultoDto->getMontoProcesadoUSD();
                            $monto_diferencia_lapso = $monto_diferencia_lapso + $DetalleDepositoBultoDto->getMontoDiferenciaUSD();
                            $monto_declarado_final  = $monto_declarado_final  + $DetalleDepositoBultoDto->getMontoDeclaradoUSD();
                            $monto_procesado_final  = $monto_procesado_final  + $DetalleDepositoBultoDto->getMontoProcesadoUSD();
                            $monto_diferencia_final = $monto_diferencia_final + $DetalleDepositoBultoDto->getMontoDiferenciaUSD();
                            $NumeroTransaccion      = $DetalleDepositoBultoDto->getNumeroTransaccion();
                            $MontoDeclarado         = $DetalleDepositoBultoDto->getMontoDeclaradoUSD();
                            $MontoProcesado         = $DetalleDepositoBultoDto->getMontoProcesadoUSD();
                            $MontoDiferencia        = $DetalleDepositoBultoDto->getMontoDiferenciaUSD();
                            $clase_diferencia       = ($DetalleDepositoBultoDto->getMontoDiferenciaUSD() != 0) ? $diferenciaContenedor  : "";
                        } elseif ($DetalleDepositoBultoDto->getMontoProcesadoEUR() > 0) {
                            $simbologia = "€";
                            $tipoMoneda = "EUR";
                            $monto_declarado_lapso  = $monto_declarado_lapso  + $DetalleDepositoBultoDto->getMontoDeclaradoEUR();
                            $monto_procesado_lapso  = $monto_procesado_lapso  + $DetalleDepositoBultoDto->getMontoProcesadoEUR();
                            $monto_diferencia_lapso = $monto_diferencia_lapso + $DetalleDepositoBultoDto->getMontoDiferenciaEUR();
                            $monto_declarado_final  = $monto_declarado_final  + $DetalleDepositoBultoDto->getMontoDeclaradoEUR();
                            $monto_procesado_final  = $monto_procesado_final  + $DetalleDepositoBultoDto->getMontoProcesadoEUR();
                            $monto_diferencia_final = $monto_diferencia_final + $DetalleDepositoBultoDto->getMontoDiferenciaEUR();
                            $NumeroTransaccion      = $DetalleDepositoBultoDto->getNumeroTransaccion();
                            $MontoDeclarado         = $DetalleDepositoBultoDto->getMontoDeclaradoEUR();
                            $MontoProcesado         = $DetalleDepositoBultoDto->getMontoProcesadoEUR();
                            $MontoDiferencia        = $DetalleDepositoBultoDto->getMontoDiferenciaEUR();
                            $clase_diferencia       = ($DetalleDepositoBultoDto->getMontoDiferenciaEUR() != 0) ? $diferenciaContenedor  : "";
                        }

                        if ($DetalleDepositoBultoDto->getCantidadChequesCLP() > 0 || $DetalleDepositoBultoDto->getCantidadChequesUSD() > 0 || $DetalleDepositoBultoDto->getCantidadChequesEUR() > 0) {
                            $monto_declarado_cheque = $monto_declarado_cheque + $DetalleDepositoBultoDto->getCantidadChequesCLP() + $DetalleDepositoBultoDto->getCantidadChequesUSD() + $DetalleDepositoBultoDto->getCantidadChequesEUR();
                            $tipo_valor = "CHEQUE";
                        } else {
                            $monto_declarado_efectivo = $monto_declarado_efectivo + $DetalleDepositoBultoDto->getCantidadChequesCLP() + $DetalleDepositoBultoDto->getCantidadChequesUSD() + $DetalleDepositoBultoDto->getCantidadChequesEUR();
                            $tipo_valor = "EFECTIVO";
                        }

                        $resultadoJsonLista[$keyB] = array(
                            'clase_diferencia' => $clase_diferencia,
                            'NumeroTransaccion' => $NumeroTransaccion,
                            'tipoMoneda' => $tipoMoneda,
                            'MontoDeclarado' => $tipoMoneda . " " . $this->convertirTipoMoneda($tipoMoneda, $MontoDeclarado),
                            'MontoProcesado' => $tipoMoneda . " " . $this->convertirTipoMoneda($tipoMoneda, $MontoProcesado),
                            'MontoDiferencia' => $tipoMoneda . " " . $this->convertirTipoMoneda($tipoMoneda, $MontoDiferencia),
                            'tipo_valor' => $tipo_valor,
                        );
                    }
                }

                $monto_total_procesado = $monto_procesado_lapso + $monto_diferencia_lapso;

                if ($monto_declarado_lapso == $monto_total_procesado) {
                    $status_proceso = "CUADRADO";
                } else {
                    $status_proceso = "DESCUADRE";
                }

                $resultadoJson = [
                    'codigoRemito' => $DetalleRecuentoBultoDto->getCodigoRemito(),
                    'status_proceso' => $status_proceso,
                    'monto_procesado_lapso' => $simbologia . $this->convertirTipoMoneda($tipoMoneda, $monto_procesado_lapso),
                    'monto_diferencia_lapso' => $simbologia  . $this->convertirTipoMoneda($tipoMoneda, $monto_diferencia_lapso),
                    'monto_declarado_lapso' => $simbologia  . $this->convertirTipoMoneda($tipoMoneda, $monto_declarado_lapso),
                    'monto_total_procesado' => $simbologia  . $this->convertirTipoMoneda($tipoMoneda, $monto_total_procesado),
                    'listaDepositos' => $resultadoJsonLista
                ];
            }

            $this->io->setDatos($resultadoJson);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/asigna-traspaso-cajero', name: 'asignarTraspasoCajero', methods: 'PUT')]
    public function asignaCajeroTraspaso(Request $request): JsonResponse
    {
        try {
            $mRecuentoManejador = new RecuentoManejador();
            $data                        = json_decode($request->getContent(), true);

            $id_proceso_cajero_traspasar = $data['id_proceso_cajero_traspaso'];
            $listaProcesoBulto          = $data['listaPuntosServicios'];
            $listaProcesoBultoCambiados = [];

            foreach ($listaProcesoBulto as $idProcesoBulto) {
                $ProcesoBultoDto = $mRecuentoManejador->recuperarProcesoBultoOP($idProcesoBulto);
                $ProcesoBultoDto->setProcesoCajero($id_proceso_cajero_traspasar);
                $bool = $mRecuentoManejador->modificarProcesoBulto($ProcesoBultoDto);
                if ($bool) {
                    array_push($listaProcesoBultoCambiados, $ProcesoBultoDto);
                }
            }
            $this->io->setDatos($listaProcesoBultoCambiados);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/asigna-cajero', name: 'asignarCajero', methods: 'POST')]
    public function asignarCajero(Request $request): JsonResponse
    {
        try {
            $data                   = json_decode($request->getContent(), true);
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();
            $mRecuentoManejador         = new RecuentoManejador();
            $id_objetos_valorados       = $data['id_objeto_valorado_asigna'];
            $id_proceso_cajero          = $data['id_proceso_cajero'];
            $url                        = $data['listaObjetoValorados'];
            $precintos                  = explode("#", $url);
            $fecha = date("Y-m-d H:i:s");

            //unset($precintos[0]);
            $DetalleProcesoRemitoDto = $mRecuentoManejador->recuperarDetalleProcesoRemito(null, null, $id_objetos_valorados);

            if ($DetalleProcesoRemitoDto->getIdObjetoValorado() != null) {

                $DetalleRemitoDto = $mObjetosValoradosManejador->recuperarDetalleRemito(null, $DetalleProcesoRemitoDto->getIdObjetoValorado());

                foreach ($DetalleRemitoDto->getListaBultos() as $ObjetoValoradoDto) {
                    if (in_array($ObjetoValoradoDto->getId(), $precintos)) {
                        $dtoProcesoBulto = new ProcesoBultoDto();
                        $dtoProcesoBulto->setProcesoRemito($DetalleProcesoRemitoDto->getIdProcesoRemito());
                        $dtoProcesoBulto->setProcesoCajero($id_proceso_cajero);
                        $dtoProcesoBulto->setProcesoSupervisor($DetalleProcesoRemitoDto->getSupervisor()->getId());
                        $dtoProcesoBulto->setProcesoEstado(1);
                        $dtoProcesoBulto->setObjetoValorado($ObjetoValoradoDto->getId());
                        $dtoProcesoBulto->setFechaProceso(date("Y-m-d"));
                        $dtoProcesoBulto->setHoraAsignacionCajero($fecha);
                        $mRecuentoManejador->insertarProcesoBulto($dtoProcesoBulto);
                    }
                }

                $DetalleProcesoRemitoDto = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $id_objetos_valorados);

                if (count($DetalleRemitoDto->getListaBultos()) == count($DetalleProcesoRemitoDto->getListaProcesos())) {
                    $procesoRemitoDto = $mRecuentoManejador->recuperarProcesoRemitoOP($DetalleProcesoRemitoDto->getIdProcesoRemito());
                    $procesoRemitoDto->setEstadoProceso(3);
                    $mRecuentoManejador->modificarProcesoRemito($procesoRemitoDto);
                }
            }
            $DetalleRemitoDto = $mObjetosValoradosManejador->recuperarDetalleRemito(null, $id_objetos_valorados);

            $this->io->setDatos($DetalleRemitoDto->getListaBultos());
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }
        return $this->json($this->io);
    }

    #[Route('/api/supervisor/asignar-remesa', name: 'asignarRemesa', methods: 'PUT')]
    public function confirmarRemesa(Request $request): JsonResponse
    {
        try {
            $data               = json_decode($request->getContent(), true);
            $idObjetoValorado   = $data['idObjetoValorado'];
            $bool               = false;
            $ProcesoRemitoDto   = null;
            $mRecuentoManejador = new RecuentoManejador(); 
            $DetalleRecuentoRemitoDto = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $idObjetoValorado);
            if ($DetalleRecuentoRemitoDto->getIdProcesoRemito() != null) {
                $ProcesoRemitoDto = $mRecuentoManejador->recuperarProcesoRemitoOP($DetalleRecuentoRemitoDto->getIdProcesoRemito());
               
                if ($ProcesoRemitoDto != null) {
                    $ProcesoRemitoDto->setEstadoProceso(2);
                    $ProcesoRemitoDto->setHoraRecepcionSupervisor(date("Y-m-d H:i:s"));
                    $bool = $mRecuentoManejador->modificarProcesoRemito($ProcesoRemitoDto);
                }
            }

            $this->io->setDatos(array("modificado" => $bool, "procesoRemito" => $ProcesoRemitoDto));
        } catch (Exception $e) {
            $this->io->manejadorError($e);
            return $this->json($this->io);
        }
        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-bultos-asignar-cajeros/{id_remitos_get_bultos}', name: 'recuperarBultosAsignarCajeros', methods: 'GET')]
    public function despliegaAsignaCajero(Request $request, $id_remitos_get_bultos): JsonResponse
    {
        try {
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();
            $mRecuentoManejador         = new RecuentoManejador();
            $arrayIdObjetosValoradoProceso  = array();

            $DetalleRemitoDto        = $mObjetosValoradosManejador->recuperarDetalleRemito(null, $id_remitos_get_bultos);
            $DetalleProcesoRemitoDto = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $id_remitos_get_bultos);

            $resultadoJsonBultos = [];

            foreach ($DetalleProcesoRemitoDto->getListaProcesos() as $DetalleRecuentoBultoDto) {
                array_push($arrayIdObjetosValoradoProceso, $DetalleRecuentoBultoDto->getIdObjetoValorado());
            }

            $contador = count($DetalleRemitoDto->getListaBultos());
            if ($contador > 0) {
                $i = 1;
                $resultadoJsonBultos = [];
                foreach ($DetalleRemitoDto->getListaBultos() as $ObjetoValorado) {

                    if ($ObjetoValorado->getId() != null) {

                        if (!in_array($ObjetoValorado->getId(), $arrayIdObjetosValoradoProceso)) {
                            array_push($resultadoJsonBultos, array(
                                'correlativo' => $i,
                                'id' => $ObjetoValorado->getId(),
                                'codigoObjeto' => $ObjetoValorado->getCodigoObjeto()
                            ));

                            $i++;
                        }
                    }
                }
            }

            $this->io->setDatos([
                    'contador' => $contador,
                    'id_remitos_get_bultos' => $id_remitos_get_bultos,
                    'listaBultos' => $resultadoJsonBultos
                ]);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-remito/{remito}', name: 'recuperar-remito', methods: 'GET')]
    public function datosremito(Request $request, $remito): JsonResponse
    {
        try {
            $remito_carga_datos = $remito;

            $mObjetosValoradosManejador   = new ObjetosValoradosManejador();
            $mRecuentoManejador           = new RecuentoManejador();
            $mClienteManejador            = new ClienteManejador();

            $DetalleRemitoDto  = $mObjetosValoradosManejador->recuperarDetalleRemito(null, $remito_carga_datos);
            $ClienteDto        = $mClienteManejador->recuperarClientesOP($DetalleRemitoDto->getPuntoRetiro()->getCliente());

            $resultadoJson = [];
            if ($DetalleRemitoDto->getIdObjetoValorado() != null) {

                $arrayDetalleMontosRemitoDto = $mObjetosValoradosManejador->recuperarListaDetalleMontoRemito(null, $remito_carga_datos);
                $DetalleRecuentoRemitoDto = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $DetalleRemitoDto->getIdObjetoValorado());

                $resultadoJsonCajeros = [];

                if (count($DetalleRecuentoRemitoDto->getListaProcesos()) > 0) {
                    $arrayIdCajero = array();
                    foreach ($DetalleRecuentoRemitoDto->getListaProcesos() as $DetalleRecuentoBultoDto) {
                        if (!in_array($DetalleRecuentoBultoDto->getCajero()->getId(), $arrayIdCajero)) {
                            $UsuarioDto = $DetalleRecuentoBultoDto->getCajero();
                            array_push($arrayIdCajero, $UsuarioDto->getId());
                            array_push($resultadoJsonCajeros,  $UsuarioDto);
                        }
                    }
                }

                $resultadoJson = [
                    'Cliente' => $ClienteDto,
                    'DetalleMontosRemito' => $arrayDetalleMontosRemitoDto,
                    'Cajeros' => $resultadoJsonCajeros,
                    'DetalleRemito' => $DetalleRemitoDto
                ];
            }

            $this->io->setDatos($resultadoJson);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }
        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-crea-remito', name: 'recuperarCreaRemito', methods: 'GET')]
    public function reiniciarCreaRemito(Request $request): JsonResponse
    {
        try {
            $mClienteManejador      = new ClienteManejador();
            $mBovedaManejador       = new BovedaManejador();

            $arrayClienteBanco      = $mClienteManejador->recuperarListaBanco();
            $arrayCustodiaDto       = $mBovedaManejador->recuperarCustodias();
            $arrayClienteDto        = $mClienteManejador->recuperarClientes();

            $arraySelectBancos = array();
            $arraySelectPuntos = array();
            $arraySelectCliente = array();

            foreach ($arrayCustodiaDto as $CustodiaDto) {
                if ($CustodiaDto->getTipoCustodia() == 3) {
                    foreach ($arrayClienteBanco as $ClienteBanco) {
                        if ($ClienteBanco->getId() == $CustodiaDto->getBanco()) {
                            array_push($arraySelectBancos, array("id" => $CustodiaDto->getId(), "text" => strtoupper($ClienteBanco->getNombreCliente())));
                            break;
                        }
                    }
                }
            }

            #cliente
            foreach ($arrayClienteDto as $ClienteDto) {
                array_push($arraySelectCliente, array("id" => $ClienteDto->getId(), "text" => strtoupper($ClienteDto->getNombreCliente())));
            }

            $this->io->setDatos([
                "custodiasTemporales" => $arraySelectBancos,
                "cliente" => $arraySelectCliente,
                "punto" => $arraySelectPuntos
            ]);

        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }
        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-custodia/{idProcesoSupervisor}/{idcustodia}', name: 'recuperarCustodia', methods: 'GET')]
    public function seleccionCustodia(Request $request, $idProcesoSupervisor, $idcustodia): JsonResponse
    {
        try {
            $mPreparacionManejador      = new PreparacionManejador();
            $mClienteManejador          = new ClienteManejador();
            $mRecuentoManejador         = new RecuentoManejador();
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();

            $resultadoJson = [];

            $DetalleMontoActualTraspasosCajeroDto   = $mPreparacionManejador->recuperarMontoSupervisorTraspasosCajeros($idProcesoSupervisor, $idcustodia);
            $arrayDenominacionCambio                = $mClienteManejador->recuperarDenominacionesTipoCambio();
            $DetalleTraspasoCajeroASupervisor       = $mPreparacionManejador->recuperarTraspasoCajeroASupervisor($idProcesoSupervisor);
            $procesoCajeroDto = $DetalleTraspasoCajeroASupervisor->getProcesoCajero();


            #busca remitos creados

            $arrayProcesoRemesaConfeccionSupervisor = $mRecuentoManejador->recuperarProcesoRemesaConfeccionSupervisor($idProcesoSupervisor, $idcustodia);
            foreach ($arrayProcesoRemesaConfeccionSupervisor as $keyR => $remesas) {
                $DetalleRecuentoRemitoDto1 = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $remesas->getIdObjetoValorado());
                if ($DetalleRecuentoRemitoDto1->getIdObjetoValorado() != null) {
                    $ObjetoValoradoDto = $mObjetosValoradosManejador->recuperarObjetosValoradoOP($DetalleRecuentoRemitoDto1->getIdObjetoValorado());
                    $PuntoDto   = $mClienteManejador->recuperarPuntoOP($ObjetoValoradoDto->getPuntoRemesa());
                    $Cliente    = $mClienteManejador->recuperarClientesOP($PuntoDto->getCliente());
                    $objeto     = $DetalleRecuentoRemitoDto1->getIdObjetoValorado();
                    $cliente    = $Cliente->getNombreCliente();
                    $punto      = $PuntoDto->getNombrePunto();
                    $monto      = 0;
                    $simbologia = "";
                    $arrayRemesaComposicion = $mObjetosValoradosManejador->recuperarListaRemesaComposicion($DetalleRecuentoRemitoDto1->getIdObjetoValorado());
                    foreach ($arrayRemesaComposicion as $RemesaComposicion) {
                        foreach ($arrayDenominacionCambio as $DenominacionCambio) {
                            if ($DenominacionCambio->getId() == $RemesaComposicion->getIdDenominacion()) {
                                $monto      = $monto + ($RemesaComposicion->getCantidad() * $DenominacionCambio->getMonto());
                                $simbologia = $DenominacionCambio->getTipoValor()->getTipoCambio()->getSimboloCambio();
                            }
                        }
                    }

                    $monto = $simbologia . " " . number_format($monto, 0, "", ".");
                    array_push($resultadoJson, array(
                        'objeto' => $objeto,
                        'id_proceso_supervisor' => $idProcesoSupervisor,
                        'idcustodia' => $idcustodia,
                        'cliente' => $cliente,
                        'punto' => $punto,
                        'monto' => $monto,
                        'total_bulto' => count($DetalleRecuentoRemitoDto1->getListaProcesos()),
                        'objetoValorado' => $ObjetoValoradoDto
                    ));
                }
            }

            $objeto = new stdClass();
            $objeto->listado_cargas = $resultadoJson;
            $objeto->procesoCajero = $procesoCajeroDto;
            $objeto->detalleMontoActualTraspasosCajero = $DetalleMontoActualTraspasosCajeroDto;

            $this->io->setDatos($objeto);

        } catch (Exception $e) {
            $this->io->manejadorError($e);
            
        }
        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-detalle-remito/{idProcesoSupervisor}/{idcustodia}/{idObjetoValorado}', name: 'recuperarDetalleRemito', methods: 'GET')]
    public function detalleRemito(Request $request, $idProcesoSupervisor, $idcustodia, $idObjetoValorado): JsonResponse
    {
        try {
            $mRecuentoManejador = new RecuentoManejador();
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();
            $mClienteManejador = new ClienteManejador();

            $DetalleRecuentoRemitoDto1 = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $idObjetoValorado);
            $DetalleRecuentoRemitoDto2 = $mRecuentoManejador->recuperarDetalleProcesoRemito($DetalleRecuentoRemitoDto1->getIdProcesoRemito());

            $detalle_remito = $DetalleRecuentoRemitoDto1->getCodigoRemito();
            $detalle_cliente = $DetalleRecuentoRemitoDto2->getClienteDto()->getNombreCliente();
            $detalle_punto = $DetalleRecuentoRemitoDto2->getPunto()->getNombrePunto();

            $arrayRemesaComposicion     = $mObjetosValoradosManejador->recuperarListaRemesaComposicion($idObjetoValorado);
            $arrayDenominacionCambio    = $mClienteManejador->recuperarDenominacionesTipoCambio();

            $tipo = strtoupper($mClienteManejador->recuperarDenominacionesTipoCambio($arrayRemesaComposicion[0]->getIdDenominacion())[0]->getTipoValor()->getTipoCambio()->getNombreTipoCambio());
            $valor["Billete20000CLP"]  = 0;
            $valor["Billete10000CLP"]  = 0;
            $valor["Billete5000CLP"]  = 0;
            $valor["Billete2000CLP"]   = 0;
            $valor["Billete1000CLP"]   = 0;
            $valor["Billete500CLP"]   = 0;
            $valor["Billete100USD"]  = 0;
            $valor["Billete50USD"]   = 0;
            $valor["Billete20USD"]   = 0;
            $valor["Billete10USD"]   = 0;
            $valor["Billete5USD"]    = 0;
            $valor["Billete2USD"]    = 0;
            $valor["Billete1USD"]    = 0;
            $valor["Billete500EUR"]  = 0;
            $valor["Billete200EUR"]  = 0;
            $valor["Billete100EUR"]  = 0;
            $valor["Billete50EUR"]   = 0;
            $valor["Billete20EUR"]   = 0;
            $valor["Billete10EUR"]   = 0;
            $valor["Billete5EUR"]    = 0;
            foreach ($arrayRemesaComposicion as $RemesaComposicion) {
                foreach ($arrayDenominacionCambio as $DenominacionCambio) {
                    if ($DenominacionCambio->getId() == $RemesaComposicion->getIdDenominacion()) {
                        $nombre =  "Billete" . $DenominacionCambio->getMonto() . $tipo;
                        $valor[$nombre] = number_format($RemesaComposicion->getCantidad() * $DenominacionCambio->getMonto(), 0, "", ".");
                    }
                }
            }


            $resultadoJsonCLP = [];
            $resultadoJsonUSD = [];
            $resultadoJsonEUR = [];

            if ($tipo == "CLP") {
                $resultadoJsonCLP = [
                    'Billete20000CLP' => $valor["Billete20000CLP"],
                    'Billete10000CLP' => $valor["Billete10000CLP"],
                    'Billete5000CLP' => $valor["Billete5000CLP"],
                    'Billete2000CLP' => $valor["Billete2000CLP"],
                    'Billete1000CLP' => $valor["Billete1000CLP"],
                    'Billete500CLP' => $valor["Billete500CLP"],
                ];
            } else if ($tipo == "USD") {
                $resultadoJsonUSD  = [
                    'Billete100USD' => $valor["Billete100USD"],
                    'Billete50USD' => $valor["Billete50USD"],
                    'Billete20USD' => $valor["Billete20USD"],
                    'Billete10USD' => $valor["Billete10USD"],
                    'Billete5USD' => $valor["Billete5USD"],
                    'Billete2USD' => $valor["Billete2USD"],
                    'Billete1USD' => $valor["Billete1USD"],
                ];
            } else if ($tipo == "EUR") {
                $resultadoJsonEUR  = [
                    'Billete500EUR' => $valor["Billete500EUR"],
                    'Billete200EUR' => $valor["Billete200EUR"],
                    'Billete100EUR' => $valor["Billete100EUR"],
                    'Billete50EUR' => $valor["Billete50EUR"],
                    'Billete20EUR' => $valor["Billete20EUR"],
                    'Billete10EUR' => $valor["Billete10EUR"],
                    'Billete5EUR' => $valor["Billete5EUR"],
                ];
            }


            $listaPrecintos = "";
            foreach ($DetalleRecuentoRemitoDto1->getListaProcesos() as $DetalleRecuentoBultoDto) {

                $listaPrecintos = $listaPrecintos . "<p>" . $DetalleRecuentoBultoDto->getCodigoPrecinto() . "</p>";
            }

            $objeto = new stdClass();
            $objeto->billeteCLP = $resultadoJsonCLP;
            $objeto->billeteUSD = $resultadoJsonUSD;
            $objeto->billeteEUR = $resultadoJsonEUR;
            $objeto->IdObjetoValorado = $DetalleRecuentoRemitoDto1->getIdObjetoValorado();
            $objeto->id_proceso_supervisor = $idProcesoSupervisor;
            $objeto->idcustodia = $idcustodia;
            $objeto->detalle_precintos = $listaPrecintos;
            $objeto->tipo = $tipo;
            $objeto->detalle_remito = $detalle_remito;
            $objeto->detalle_cliente = $detalle_cliente;
            $objeto->detalle_punto = $detalle_punto;

            $this->io->setDatos($objeto);
       
        } catch (Exception $e) {
            $this->io->manejadorError($e);
            
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/eliminar-detalle-remito/{idProcesoSupervisor}/{idcustodia}/{idObjetoValorado}', name: 'eliminarDetalleRemito', methods: 'DELETE')]
    public function eliminarRemito(Request $request, $idProcesoSupervisor, $idcustodia, $idObjetoValorado): JsonResponse
    {
        try {
            $mPreparacionManejador      = new PreparacionManejador();
            $mClienteManejador          = new ClienteManejador();
            $mRecuentoManejador         = new RecuentoManejador();
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();

            $DetalleRecuentoRemitoDto1              = $mRecuentoManejador->recuperarDetalleRecuentoRemito(null, $idObjetoValorado);
            $DetalleMontoActualTraspasosCajeroDto   = $mPreparacionManejador->recuperarMontoSupervisorTraspasosCajeros($idProcesoSupervisor, $idcustodia);
            $arrayRemesaSupervisorDto               = $mRecuentoManejador->recuperarProcesoRemesaConfeccionSupervisor($idProcesoSupervisor, $idcustodia);
            $arrayDenominacionCambio                = $mClienteManejador->recuperarDenominacionesTipoCambio();
            $arrayRemesaComposicion                 = $mObjetosValoradosManejador->recuperarListaRemesaComposicion($idObjetoValorado);
            $arrayDenominacion                      = array();

            foreach ($DetalleMontoActualTraspasosCajeroDto->getListaMontos() as $MontoSupervisorActualCajeroDetalleDto) {
                foreach ($arrayDenominacionCambio as $DenominacionCambio) {
             
                    if ($DenominacionCambio->getId() == $MontoSupervisorActualCajeroDetalleDto->getDenominacion()->getId()) {
                        array_push($arrayDenominacion, array(
                            "idboveda" => $MontoSupervisorActualCajeroDetalleDto->getId(),
                            "denominacion" => $DenominacionCambio->getId(),
                            "monto" => $DenominacionCambio->getMonto(),
                            "nombre" => $DenominacionCambio->getTipoValor()->getNombreTipoValor(),
                            "tipoMoneda" => $DenominacionCambio->getTipoValor()->getTipoCambio()->getNombreTipoCambio()
                        ));
                    }
                }
            }

            foreach ($arrayDenominacion as $Denominacion) {
                foreach ($arrayRemesaComposicion as $RemesaComposicion) {
                    if ($RemesaComposicion->getIdDenominacion() == $Denominacion["denominacion"]) {
                        $this->montoSupervisorActual($Denominacion["idboveda"], ($RemesaComposicion->getCantidad() *  $Denominacion["monto"]), true);
                    }
                }
            }

            $ProcesoRemito = $mRecuentoManejador->recuperarProcesoRemitoOP($DetalleRecuentoRemitoDto1->getIdProcesoRemito());

            foreach ($DetalleRecuentoRemitoDto1->getListaProcesos() as $DetalleRecuentoBultoDto_Proceso) {
                try {
                    $mRecuentoManejador->eliminarProcesoBulto($DetalleRecuentoBultoDto_Proceso->getIdProcesoBulto());
                } catch (Exception $e) {
                }
            }

            $estado = $mRecuentoManejador->eliminarProcesoRemito($ProcesoRemito);

            if ($estado) {
                foreach ($arrayRemesaComposicion as $RemesaComposicion) {
                    $mObjetosValoradosManejador->eliminarRemesaComposicion($RemesaComposicion->getId());
                }

                $estado = $mObjetosValoradosManejador->eliminarObjetosValoradoOP($DetalleRecuentoRemitoDto1->getIdObjetoValorado());
            }

            foreach ($arrayRemesaSupervisorDto as $RemesaSupervisorDto) {
                if ($RemesaSupervisorDto->getIdObjetoValorado() == $DetalleRecuentoRemitoDto1->getIdObjetoValorado()) {
                    $mRecuentoManejador->eliminarProcesoRemesaConfeccionSupervisor($RemesaSupervisorDto->getId());
                }
            }

            $this->io->setDatos(['eliminado'=> $estado]);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/asignar-remito', name: 'asignarRemito', methods: 'POST')]
    public function ingresarRemito(Request $request): JsonResponse
    {
        try {
            $mPreparacionManejador      = new PreparacionManejador();
            $mObjetosValoradosManejador = new ObjetosValoradosManejador();
            $mRecuentoManejador         = new RecuentoManejador();
            $mClienteManejador          = new ClienteManejador();

            $data               = json_decode($request->getContent(), true);
            $idProcesoSupervisor = $data['idProcesoSupervisor'];
            $remito             = $data['remito'];
            $cajero             = $data['cajero'];
            $punto              = $data['punto'];
            $idcustodia         = $data['custodia'];
            $arrayPrecinto      = json_decode($data['precinto']);
            $billetes           = json_decode($data['billetes']);

            $asignado       = false;

            $objetoValoradoDto = new ObjetoValoradoDto();
            $objetoValoradoDto->setPuntoRetiro($punto);
            $objetoValoradoDto->setPuntoRemesa($punto);
            $objetoValoradoDto->setTipoObjetoValorado(5);
            $objetoValoradoDto->setCodigoObjeto($remito);
            $objetoValoradoDto->setEstadoObjetoValorado(4);
            $objetoValoradoDto->setIdEstado(5);
            $objetoValoradoDto->setFechaHoraIngresoRecepcion(date("Y-m-d H:i:s"));
            $objetoValoradoDto->setFechaRecepcion(date("Y-m-d H:i:s"));
            $objetoValoradoDto->setFechaHoraIngresoAduana(date("Y-m-d H:i:s"));
            $objetoValoradoDto->setPuntoServicioEntrada(0);
            $objetoValoradoDto->setTipoServicioObjeto(2);
            $idObjeroPadre = $mObjetosValoradosManejador->insertarRemito($objetoValoradoDto);

            if ($idObjeroPadre > 0) {

                $procesoRemesaConfeccionSupervisor =  new ProcesoRemesaConfeccionSupervisorDto();
                $procesoRemesaConfeccionSupervisor->setIdObjetoValorado($idObjeroPadre);
                $procesoRemesaConfeccionSupervisor->setIdSupervisor($idProcesoSupervisor);
                $procesoRemesaConfeccionSupervisor->setCustodia($idcustodia);
                $procesoRemesaConfeccionSupervisor->setFechaIngreso(date("Y-m-d H:i:s"));
                $mRecuentoManejador->IngresarProcesoRemesaConfeccionSupervisor($procesoRemesaConfeccionSupervisor);
                $arrayObjetoValoradoHijos = array();
                foreach ($arrayPrecinto as $precinto) {
                    $objetoValoradoDto = new ObjetoValoradoDto();
                    $objetoValoradoDto->setPuntoRetiro($punto);
                    $objetoValoradoDto->setPuntoRemesa($punto);
                    $objetoValoradoDto->setTipoObjetoValorado(6);
                    $objetoValoradoDto->setObjetoPadre($idObjeroPadre);
                    $objetoValoradoDto->setCodigoObjeto($precinto);
                    $objetoValoradoDto->setEstadoObjetoValorado(4);
                    $objetoValoradoDto->setIdEstado(5);
                    $objetoValoradoDto->setFechaHoraIngresoRecepcion(date("Y-m-d H:i:s"));
                    $objetoValoradoDto->setFechaRecepcion(date("Y-m-d H:i:s"));
                    $objetoValoradoDto->setFechaHoraIngresoAduana(date("Y-m-d H:i:s"));
                    $objetoValoradoDto->setPuntoServicioEntrada(0);
                    $objetoValoradoDto->setTipoServicioObjeto(2);
                    $idObjetoHijo = $mObjetosValoradosManejador->insertarRemito($objetoValoradoDto);
                    array_push($arrayObjetoValoradoHijos, $idObjetoHijo);
                }

                $procesoRemitoDto = new ProcesoRemitoDto();
                $procesoRemitoDto->setObjetoValorado($idObjeroPadre);
                $procesoRemitoDto->setEstadoProceso(6);
                $procesoRemitoDto->setProcesoSupervisor($idProcesoSupervisor);
                $procesoRemitoDto->setFechaProceso(date("Y-m-d H:i:s"));
                $procesoRemitoDto->setHoraRecepcionSupervisor(date("Y-m-d H:i:s"));
                $procesoRemitoDto->setHoraCierreProceso(date("Y-m-d H:i:s"));
                $procesoRemitoDto->setExisteDiferencia(0);
                $procesoRemitoDto->setSesionGlobal(0);

                $idRemito = $mRecuentoManejador->insertarProcesoRemito($procesoRemitoDto);
                foreach ($arrayObjetoValoradoHijos as $ObjetoValoradoHijos) {
                    $procesoBultoDto = new ProcesoBultoDto();
                    $procesoBultoDto->setProcesoRemito($idRemito);
                    $procesoBultoDto->setProcesoCajero($cajero);
                    $procesoBultoDto->setProcesoEstado(3);
                    $procesoBultoDto->setProcesoSupervisor($idProcesoSupervisor);
                    $procesoBultoDto->setHoraInicioProceso(date("Y-m-d H:i:s"));
                    $procesoBultoDto->setHoraTerminoProceso(date("Y-m-d H:i:s"));
                    $procesoBultoDto->setFechaProceso(date("Y-m-d H:i:s"));
                    $procesoBultoDto->setObjetoValorado($ObjetoValoradoHijos);
                    $mRecuentoManejador->insertarProcesoBulto($procesoBultoDto);
                }

                $DetalleMontoActualTraspasosCajeroDto   = $mPreparacionManejador->recuperarMontoSupervisorTraspasosCajeros($idProcesoSupervisor, $idcustodia);

                $montoTotal = 0;
                foreach ($billetes as $billetesDenominacion) {
                    $RemesaComposicion = new RemesaComposicionDto();
                    $RemesaComposicion->setIdDenominacion($billetesDenominacion->denominacion);
                    $RemesaComposicion->setIdObjetoValorado($idObjeroPadre);
                    $RemesaComposicion->setCantidad($billetesDenominacion->monto / $billetesDenominacion->montoDenominacion);
                    $montoTotal += $billetesDenominacion->monto;
                    $mObjetosValoradosManejador->insertarRemesaComposicion($RemesaComposicion);
                    foreach ($DetalleMontoActualTraspasosCajeroDto->getListaMontos() as $MontoSupervisorActualCajeroDetalleDto) {
                        if (
                            $MontoSupervisorActualCajeroDetalleDto->getDenominacion()->getId() == $billetesDenominacion->denominacion &
                            $MontoSupervisorActualCajeroDetalleDto->getComposicion()->getId() == $billetesDenominacion->composicion
                        ) {
                            $this->montoSupervisorActual($MontoSupervisorActualCajeroDetalleDto->getId(), $billetesDenominacion->monto, null, true);
                        }
                    }
                }

                $tipoValor  = 0;
                $arrayDenominacionCambio = $mClienteManejador->recuperarDenominacionesTipoCambio();
                foreach ($arrayDenominacionCambio as $DenominacionCambio) {
                    if ($billetes[0]->denominacion == $DenominacionCambio->getId()) {
                        $tipoValor = $DenominacionCambio->getTipoValor()->getId();
                    }
                }

                $mObjetosValoradosManejador->insertarMontoARemito($montoTotal, $tipoValor, $idObjeroPadre);

                $asignado = true;
            } 

            $this->io->setDatos(['asignado'=>$asignado]);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-monto-supervisor-traspaso-cajero/{idProcesoSupervisor}/{tipoCustodia}', name: 'recuperarTraspasoCajeroSupervisor', methods: 'GET')]
    public function recuperarTraspasoCajeroSupervisor(Request $request, $idProcesoSupervisor, $tipoCustodia): JsonResponse
    {
        try {
            $mPreparacionManejador = new PreparacionManejador();
            $DetalleMontoActualTraspasosCajeroDto   = $mPreparacionManejador->recuperarMontoSupervisorTraspasosCajeros($idProcesoSupervisor, $tipoCustodia);
            $this->io->setDatos($DetalleMontoActualTraspasosCajeroDto);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/asignar-transferencia-colas-cajero', name: 'asignarTransferenciaColasCajero', methods: 'PUT')]
    public function transferenciaColasCajero(Request $request): JsonResponse
    {
        try {
            $mPreparacionManejador  = new PreparacionManejador();
            $data                   = json_decode($request->getContent(), true);
            $idProcesoSupervisor    = $data['idProcesoSupervisor'];
            $idCustodia             = $data['banco'];
            $cajero                 = $data['cajero'];
            $listaMontos            = $data['listaMontos'];
            $datosObtenido          = $data['datosObtenido'];

            $arrayTraspasoSupervisorACajeroDetalle = array();
            foreach ($listaMontos as $value) {
                $TraspasoSupervisorACajeroDetalleDto  = new TraspasoSupervisorACajeroDetalleDto();
                $TraspasoSupervisorACajeroDetalleDto->setIdTraspasoSupervisorACajero($idProcesoSupervisor);
                $TraspasoSupervisorACajeroDetalleDto->setDenominacion($value["denominacion"]);
                $TraspasoSupervisorACajeroDetalleDto->setMontoTransferido($value["monto"]);
                $TraspasoSupervisorACajeroDetalleDto->setComposicion($value["composicion"]);
                array_push($arrayTraspasoSupervisorACajeroDetalle, $TraspasoSupervisorACajeroDetalleDto);
            }

            $TraspasoSupervisorACajeroDto = new TraspasoSupervisorACajeroDto();
            $TraspasoSupervisorACajeroDto->setFechaTransferencia(date('Y-m-d'));
            $TraspasoSupervisorACajeroDto->setUsuarioTransferencia(0);
            $TraspasoSupervisorACajeroDto->setProcesoCajero($cajero);
            $TraspasoSupervisorACajeroDto->setProcesoSupervisor($idProcesoSupervisor);
            $TraspasoSupervisorACajeroDto->setConfirmado(0);
            $TraspasoSupervisorACajeroDto->setCustodia($idCustodia);
            $id = $mPreparacionManejador->traspasarDineroACajero($TraspasoSupervisorACajeroDto, $arrayTraspasoSupervisorACajeroDetalle);

            if ($id > 0) {
                switch ($datosObtenido) {
                    case 'monto_supervisor_actual_cajero':
                        $this->descontarMontoSupervisorTransferidoDesdeCajero($idProcesoSupervisor, $idCustodia, $listaMontos);
                        break;
                    case 'monto_supervisor_actual_boveda':
                        $this->descontarMontoSupervisorTransferidoDesdeBoveda($idProcesoSupervisor, $idCustodia, $listaMontos);
                        break;
                    default:
                        break;
                }
            }

            $this->io->setDatos($id);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-monto-supervisor-traspaso-boveda/{idProcesoSupervisor}/{idCustodia}', name: 'recuperarMontoSupervisor', methods: 'GET')]
    public function cargarMontosSupervisor(Request $request, $idProcesoSupervisor, $idCustodia): JsonResponse
    {
        try {
            $mPreparacionManejador  = new PreparacionManejador();
            $mClienteManejador      = new ClienteManejador();
            $mBovedaManejador       = new BovedaManejador();
            $listaMontos = [];
            $DetalleMontoActualTraspasosBovedaDto = $mPreparacionManejador->recuperarMontoActualSupervisorTraspasoBoveda($idProcesoSupervisor, $idCustodia);

            if ($DetalleMontoActualTraspasosBovedaDto->getCustodia() != null) {
                foreach ($DetalleMontoActualTraspasosBovedaDto->getListaMontos() as $MontoSupervisorActualBovedaDetalleDto) {
                    $denominacion = $mClienteManejador->recuperarDenominacionesTipoCambio($MontoSupervisorActualBovedaDetalleDto->getDenominacion());
                    array_push(
                        $listaMontos,
                        [
                            "id" => $MontoSupervisorActualBovedaDetalleDto->getId(),
                            "idMontoSupervisorActualBoveda" => $MontoSupervisorActualBovedaDetalleDto->getIdMontoSupervisorActualBoveda(),
                            "denominacion" => $denominacion[0],
                            "montoTransferido" => $MontoSupervisorActualBovedaDetalleDto->getMontoTransferido(),
                            "montoDisponible" => $MontoSupervisorActualBovedaDetalleDto->getMontoDisponible(),
                            "composicion" => $mBovedaManejador->recuperarBovedaComposicionDineroOP($MontoSupervisorActualBovedaDetalleDto->getComposicion())
                        ]
                    );
                }
            }

            $this->io->setDatos($listaMontos);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/asignar-monto-granel-supervisor-a-boveda', name: 'asignarMontoGranelSupervisorABoveda', methods: 'PUT')]
    public function asignarMontoGranelSupervisorBoveda(Request $request): JsonResponse
    {
        try {
            $mBovedaManejador  = new BovedaManejador();
            $data                   = json_decode($request->getContent(), true);
            $idUsuario              = $data['idUsuario'];
            $idProcesoSupervisor    = $data['idProcesoSupervisor'];
            $custodia_banco_id      = $data['custodia_banco_id'];
            $listaMontos            = $data['listaMontos'];
            $datosObtenido          = $data['datosObtenido'];

            $arrayBovedaTransaccionDetalle = array();

            foreach ($listaMontos as $value) {
                $BovedaTransaccionDetalle = new BovedaTransaccionDetalleDto();
                $BovedaTransaccionDetalle->setDenominacion($value["denominacion"]);
                $BovedaTransaccionDetalle->setMontoTransaccion($value["monto"]);
                array_push($arrayBovedaTransaccionDetalle, $BovedaTransaccionDetalle);
            }

            $BovedaTransaccion = new BovedaTransaccionDto();
            $BovedaTransaccion->setCustodiaOrigen($custodia_banco_id);
            $BovedaTransaccion->setCustodiaDestino($custodia_banco_id);
            $BovedaTransaccion->setFechaTransaccion(date('Y-m-d'));
            $BovedaTransaccion->setHoraTransaccion(date("H:i:s"));
            $BovedaTransaccion->setUsuarioTransaccion($idUsuario);
            $BovedaTransaccion->setTipoTransaccion(1);
            $BovedaTransaccion->setLugarFisicoOrigen(4);
            $BovedaTransaccion->setLugarFisicoDestino(5);
            $BovedaTransaccion->setMovimientoTransaccion(2);
            $BovedaTransaccion->setTransaccionExterno(0);
            $BovedaTransaccion->setProcesoEntidad(3);
            $BovedaTransaccion->setTransaccionEstado(1);
            $BovedaTransaccion->setComposicionDinero($value["composicion"]);
            $BovedaTransaccion->setContenedorDinero(1);
            $id = $mBovedaManejador->traspasarDineroABoveda($BovedaTransaccion, $arrayBovedaTransaccionDetalle);

            if ($id > 0) {
                switch ($datosObtenido) {
                    case 'monto_supervisor_actual_cajero':
                        $this->descontarMontoSupervisorTransferidoDesdeCajero($idProcesoSupervisor, $custodia_banco_id, $listaMontos);
                        break;
                    case 'monto_supervisor_actual_boveda':
                        $this->descontarMontoSupervisorTransferidoDesdeBoveda($idProcesoSupervisor, $custodia_banco_id, $listaMontos);
                        break;
                    case 'confirma_remesas_boveda':
                        $this->confirmaRemesasBoveda($id['id'], $custodia_banco_id, $listaMontos);
                        break;
                    default:
                        break;
                }
            }

            $this->io->setDatos($id);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }


    #[Route('/api/supervisor/recuperar-supervisor', name: 'recuperarSupervisor', methods: 'GET')]
    public function recuperarSupervisor(Request $request): JsonResponse
    {
        try {
            $mSalaProcesoManejador      = new SalaProcesoManejador();
            $ProcesoSesionGlobalDto     = $mSalaProcesoManejador->recuperarSesionGlobalActiva();
            $arrayDetalleSupervisorDto  = $mSalaProcesoManejador->recuperarSupervisores(1, null, $ProcesoSesionGlobalDto->getId());

            $this->io->setDatos($arrayDetalleSupervisorDto);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    #[Route('/api/supervisor/recuperar-lista-tipo-carga/{idCustodia}', name: 'recuperarListaTipoCarga', methods: 'GET')]
    public function recuperarListaTipoCarga(Request $request, $idCustodia): JsonResponse
    {
        try {
            $mPreparacionManejador  = new PreparacionManejador();
            $arrayTipoCargas        = $mPreparacionManejador->recuperarListaTipoCargas($idCustodia);
            $this->io->setDatos($arrayTipoCargas);
        } catch (Exception $e) {
            $this->io->manejadorError($e);
        }

        return $this->json($this->io);
    }

    public function confirmaRemesasBoveda($id, $custodia_banco_id, $listaMonto)
    {
        $mBovedaManejador = new BovedaManejador();

        $arrayMontos = array();
        $idComposicion = 2;
        $contenedor = 1;
        foreach ($listaMonto as $monto) {
            $BovedaResumenDetalle = new BovedaResumenDetalleDto();
            $BovedaResumenDetalle->setDenominacion($monto['denominacion']);
            $BovedaResumenDetalle->setMonto($monto['monto']);
            $idComposicion  = $monto['composicion'];
            $contenedor  = $monto['contenedor'];
            array_push($arrayMontos, $BovedaResumenDetalle);
        }

        $mBovedaManejador->asignarBovedaResumen($custodia_banco_id, $idComposicion, $contenedor, $arrayMontos);
        $bovedaTransaccionDto = $mBovedaManejador->recuperarBovedaTransaccionOP($id);
        $bovedaTransaccionDto->setId($id);
        $bovedaTransaccionDto->setTransaccionEstado(2);
        $bovedaTransaccionDto->setLugarFisicoDestino(6); //cambio lugar destino
        $bovedaTransaccionDto->setProcesoEntidad(5);      
        return $mBovedaManejador->modificarBovedaTransaccion($bovedaTransaccionDto);
    }

}

